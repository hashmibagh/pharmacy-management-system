<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Config\Database;
use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Middleware\PermissionMiddleware;
use Pharmacy\Models\Role;
use Pharmacy\Models\User;

class RoleController extends BaseController
{
    public function index(array $request): void
    {
        $roles = Role::raw('SELECT * FROM roles ORDER BY name ASC');
        foreach ($roles as &$r) {
            $r['permissions'] = Role::permissions((int) $r['id']);
            $r['users_count'] = (int) (User::rawOne(
                'SELECT COUNT(*) AS c FROM users WHERE role_id = :rid',
                [':rid' => $r['id']]
            )['c'] ?? 0);
        }
        Response::success($roles);
    }

    public function store(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'permissions' => 'nullable|array',
        ]);
        if (Role::findBy('name', trim($b['name']))) {
            throw new ApiException('Role already exists.', 422);
        }
        $permissions = $this->validatePermissionList($b['permissions'] ?? []);

        Database::beginTransaction();
        try {
            $id = (int) Role::create([
                'name' => trim($b['name']),
                'description' => isset($b['description']) ? trim((string) $b['description']) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            Role::syncPermissions($id, $permissions);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'ROLE_CREATED', 'roles', $id, null, ['name' => $b['name']]);
        Response::success($this->roleWithPermissions($id), 'Role created.', 201);
    }

    public function show(array $request): void
    {
        $role = $this->roleWithPermissions((int) $this->param($request, 'id'));
        if (!$role) {
            throw new ApiException('Role not found.', 404);
        }
        Response::success($role);
    }

    public function update(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Role::find($id);
        if (!$old) {
            throw new ApiException('Role not found.', 404);
        }
        if ($this->isSuperAdmin($old)) {
            throw new ApiException('The Super Admin role cannot be renamed or edited.', 422);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);
        $dup = Role::findBy('name', trim($b['name']));
        if ($dup && (int) $dup['id'] !== $id) {
            throw new ApiException('Role already exists.', 422);
        }
        Role::update($id, $this->filtered($b, ['name', 'description']));
        $this->audit($request, 'ROLE_UPDATED', 'roles', $id, $old, $b);
        Response::success($this->roleWithPermissions($id), 'Role updated.');
    }

    public function destroy(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Role::find($id);
        if (!$old) {
            throw new ApiException('Role not found.', 404);
        }
        if ($this->isSuperAdmin($old)) {
            throw new ApiException('The Super Admin role cannot be deleted.', 422);
        }
        $inUse = User::rawOne('SELECT COUNT(*) AS c FROM users WHERE role_id = :rid', [':rid' => $id])['c'] ?? 0;
        if ((int) $inUse > 0) {
            throw new ApiException('Cannot delete: role is assigned to users.', 422);
        }
        Database::beginTransaction();
        try {
            Role::rawExec('DELETE FROM role_permissions WHERE role_id = :rid', [':rid' => $id]);
            Role::delete($id);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        $this->audit($request, 'ROLE_DELETED', 'roles', $id, $old, null);
        Response::success(null, 'Role deleted.');
    }

    /** PUT /api/roles/{id}/permissions — replace the role's permission set. */
    public function updatePermissions(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $role = Role::find($id);
        if (!$role) {
            throw new ApiException('Role not found.', 404);
        }
        if ($this->isSuperAdmin($role)) {
            throw new ApiException('The Super Admin role always has all permissions.', 422);
        }
        $b = $this->body($request);
        if (!isset($b['permissions']) || !is_array($b['permissions'])) {
            throw new ApiException('Provide a "permissions" array of permission strings.', 422);
        }
        $permissions = $this->validatePermissionList($b['permissions']);

        Database::beginTransaction();
        try {
            Role::syncPermissions($id, $permissions);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        // Clear cached grants (per-request cache lives in PermissionMiddleware::$cache —
        // fresh on next request; nothing else to do in stateless PHP).
        $this->audit($request, 'ROLE_CHANGED', 'roles', $id, null, ['permissions' => $permissions]);
        Response::success($this->roleWithPermissions($id), 'Role permissions updated.');
    }

    /** GET /api/roles/permissions/catalog — list every known permission string. */
    public function catalog(array $request): void
    {
        Response::success(['permissions' => PermissionMiddleware::allPermissions()]);
    }

    // ---------------------------------------------------------- internals
    /** The schema has no roles.is_super_admin column — Super Admin is the role NAME. */
    private function isSuperAdmin(array $role): bool
    {
        return ($role['name'] ?? '') === 'Super Admin';
    }

    /** @return string[] validated permission strings */
    private function validatePermissionList(array $permissions): array
    {
        $valid = PermissionMiddleware::allPermissions();
        $unknown = array_diff($permissions, $valid);
        if ($unknown) {
            throw new ApiException('Unknown permissions: ' . implode(', ', $unknown), 422);
        }
        return array_values(array_unique($permissions));
    }

    private function roleWithPermissions(int $id): ?array
    {
        $role = Role::find($id);
        if (!$role) {
            return null;
        }
        $role['permissions'] = Role::permissions($id);
        return $role;
    }
}
