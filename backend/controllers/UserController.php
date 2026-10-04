<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Config\Database;
use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Auth;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Middleware\PermissionMiddleware;
use Pharmacy\Models\Role;
use Pharmacy\Models\User;

class UserController extends BaseController
{
    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = '1=1';
        $params = [];
        $search = trim((string) ($q['q'] ?? $q['search'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (u.name LIKE :s OR u.email LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        $total = User::rawOne("SELECT COUNT(*) AS c FROM users u WHERE {$where}", $params)['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = User::raw(
            "SELECT u.id, u.name, u.email, u.phone, u.avatar, u.role_id, u.status,
                    u.last_login_at, u.created_at, r.name AS role_name
             FROM users u LEFT JOIN roles r ON r.id = u.role_id
             WHERE {$where} ORDER BY u.name ASC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        Response::success([
            'data' => $data,
            'meta' => [
                'current_page' => $page, 'per_page' => $perPage,
                'total' => (int) $total, 'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    public function store(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'name'      => 'required|string|max:150',
            'email'     => 'required|email|max:190',
            'password'  => 'required|string|min:8|max:255',
            'role_id'   => 'required|integer',
            'phone'     => 'nullable|string|max:30',
            'status'    => 'nullable|in:active,inactive',
            'is_active' => 'nullable|boolean',
        ]);
        if (User::findByEmail(strtolower(trim($b['email'])))) {
            throw new ApiException('Email already exists.', 422, ['email' => ['Email already exists.']]);
        }
        $role = Role::find((int) $b['role_id']);
        if (!$role) {
            throw new ApiException('Role not found.', 404);
        }
        // Only a super admin can create another super admin.
        if ($this->isSuperAdminRole($role) && empty($request['user']['is_super_admin'])) {
            throw new ApiException('Only a Super Admin can assign the Super Admin role.', 403);
        }

        $data = $this->userData($b);
        $data['email'] = strtolower(trim($data['email']));
        $data['password_hash'] = password_hash((string) $b['password'], PASSWORD_DEFAULT);
        if (!isset($data['status'])) {
            $data['status'] = 'active';
        }
        $data['created_at'] = date('Y-m-d H:i:s');

        $id = (int) User::create($data);
        $this->audit($request, 'USER_CREATED', 'users', $id, null, ['name' => $data['name'], 'email' => $data['email']]);
        Response::success(User::public(User::withRole($id) ?? []), 'User created.', 201);
    }

    public function show(array $request): void
    {
        $u = User::withRole((int) $this->param($request, 'id'));
        if (!$u) {
            throw new ApiException('User not found.', 404);
        }
        Response::success(User::public($u));
    }

    public function update(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = User::find($id);
        if (!$old) {
            throw new ApiException('User not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'name'      => 'required|string|max:150',
            'email'     => 'required|email|max:190',
            'password'  => 'nullable|string|min:8|max:255',
            'role_id'   => 'nullable|integer',
            'phone'     => 'nullable|string|max:30',
            'status'    => 'nullable|in:active,inactive',
            'is_active' => 'nullable|boolean',
        ]);

        $dup = User::findByEmail(strtolower(trim($b['email'])));
        if ($dup && (int) $dup['id'] !== $id) {
            throw new ApiException('Email already exists.', 422);
        }
        if (!empty($b['role_id'])) {
            $role = Role::find((int) $b['role_id']);
            if (!$role) {
                throw new ApiException('Role not found.', 404);
            }
            if ($this->isSuperAdminRole($role) && empty($request['user']['is_super_admin'])) {
                throw new ApiException('Only a Super Admin can assign the Super Admin role.', 403);
            }
        }
        // Prevent locking yourself out / deactivating the last super admin.
        $deactivating = isset($b['status'])
            ? $b['status'] === 'inactive'
            : (isset($b['is_active']) && (int) $b['is_active'] === 0);
        if ($id === $this->uid($request) && $deactivating) {
            throw new ApiException('You cannot deactivate your own account.', 422);
        }

        $data = $this->userData($b);
        $data['email'] = strtolower(trim($data['email']));
        $passwordChanged = false;
        if (!empty($b['password'])) {
            $data['password_hash'] = password_hash((string) $b['password'], PASSWORD_DEFAULT);
            $passwordChanged = true;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        User::update($id, $data);

        if ($passwordChanged || $deactivating) {
            Auth::revokeAllForUser($id); // force re-login
        }
        if (!empty($b['role_id']) && (int) $b['role_id'] !== (int) ($old['role_id'] ?? 0)) {
            $this->audit($request, 'ROLE_CHANGED', 'users', $id,
                ['role_id' => $old['role_id']], ['role_id' => $b['role_id']]);
        }
        $this->audit($request, 'USER_UPDATED', 'users', $id, User::public($old), ['name' => $data['name']]);
        Response::success(User::public(User::withRole($id) ?? []), 'User updated.');
    }

    public function destroy(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = User::find($id);
        if (!$old) {
            throw new ApiException('User not found.', 404);
        }
        if ($id === $this->uid($request)) {
            throw new ApiException('You cannot delete your own account.', 422);
        }
        $oldRole = Role::find((int) ($old['role_id'] ?? 0));
        if (($oldRole && $this->isSuperAdminRole($oldRole)) || (int) ($old['role_id'] ?? 0) === 1) {
            $supers = User::rawOne(
                'SELECT COUNT(*) AS c FROM users u JOIN roles r ON r.id = u.role_id
                 WHERE r.name = \'Super Admin\' AND u.id != :id',
                [':id' => $id]
            )['c'] ?? 0;
            if ((int) $supers === 0) {
                throw new ApiException('Cannot delete the last Super Admin.', 422);
            }
        }

        Auth::revokeAllForUser($id);
        User::delete($id);
        $this->audit($request, 'USER_DELETED', 'users', $id, User::public($old), null);
        Response::success(null, 'User deleted.');
    }

    /**
     * Per-user permission overrides (grants/denies on top of the role).
     * Body: { "permissions": { "medicines.delete": true, "reports.export": false } }
     * Permission NAMES are accepted and resolved to ids server-side.
     */
    public function updatePermissions(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        if (!User::find($id)) {
            throw new ApiException('User not found.', 404);
        }
        $b = $this->body($request);
        if (!isset($b['permissions']) || !is_array($b['permissions'])) {
            throw new ApiException('Provide a "permissions" object.', 422);
        }
        $valid = PermissionMiddleware::allPermissions();
        $unknown = array_diff(array_keys($b['permissions']), $valid);
        if ($unknown) {
            throw new ApiException('Unknown permissions: ' . implode(', ', $unknown), 422);
        }

        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_permissions WHERE user_id = :uid')->execute([':uid' => $id]);
            $ins = $pdo->prepare(
                'INSERT IGNORE INTO user_permissions (user_id, permission_id, granted) VALUES (:uid, :pid, :g)'
            );
            // permissionIds returns ids in the same order as the input names.
            $ids = Role::permissionIds(array_keys($b['permissions']));
            $i = 0;
            foreach ($b['permissions'] as $perm => $granted) {
                // ids are returned in the same order as the input names.
                $pid = $ids[$i++] ?? null;
                if ($pid === null) {
                    continue;
                }
                $ins->execute([':uid' => $id, ':pid' => $pid, ':g' => $granted ? 1 : 0]);
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'PERMISSIONS_CHANGED', 'users', $id, null, $b['permissions']);
        Response::success(['user_id' => $id, 'permissions' => $b['permissions']], 'User permissions updated.');
    }

    // ---------------------------------------------------------- internals
    /** The schema has no roles.is_super_admin column — Super Admin is the role NAME. */
    private function isSuperAdminRole(array $role): bool
    {
        return ($role['name'] ?? '') === 'Super Admin';
    }

    /** Map frontend keys to real `users` columns. */
    private function userData(array $b): array
    {
        $data = $this->filtered($b, ['name', 'email', 'phone', 'role_id', 'status']);
        if (isset($b['is_active']) && !isset($b['status'])) {
            $data['status'] = ((int) $b['is_active'] === 1) ? 'active' : 'inactive';
        }
        unset($data['is_active']);
        return $data;
    }
}
