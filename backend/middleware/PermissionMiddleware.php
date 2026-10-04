<?php
declare(strict_types=1);

namespace Pharmacy\Middleware;

use Pharmacy\Config\Database;
use Pharmacy\Helpers\ApiException;

/**
 * RBAC enforcement. Checks permission strings such as "medicines.create".
 *
 * Sources (checked in order):
 *   1. Super admin (role name = 'Super Admin', surfaced as $user['is_super_admin']) → bypasses everything.
 *   2. user_permissions (per-user overrides): granted=1 allows, granted=0 denies.
 *   3. role_permissions for the user's role (via the normalized permissions table).
 *
 * A route may require several permissions — the user needs ALL of them.
 */
final class PermissionMiddleware
{
    /** @var array<int, array<string, bool>> per-request cache keyed by user id */
    private static array $cache = [];

    /**
     * @param string[] $required
     */
    public static function handle(array $request, array $required): void
    {
        $user = $request['user'] ?? null;
        if (!$user) {
            throw new ApiException('Unauthenticated.', 401);
        }
        if (!empty($user['is_super_admin'])) {
            return; // Super Admin bypasses all checks.
        }

        $granted = self::grantedPermissions((int) $user['id'], (int) ($user['role_id'] ?? 0));
        foreach ($required as $perm) {
            if (empty($granted[$perm])) {
                throw new ApiException("Forbidden: missing permission '{$perm}'.", 403);
            }
        }
    }

    /** @return array<string, bool> permission => true */
    public static function grantedPermissions(int $userId, int $roleId): array
    {
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }

        $pdo = Database::pdo();
        $granted = [];

        // Role permissions (normalized: role_permissions → permissions).
        if ($roleId > 0) {
            $stmt = $pdo->prepare(
                'SELECT p.name FROM role_permissions rp
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = :rid'
            );
            $stmt->execute([':rid' => $roleId]);
            foreach ($stmt->fetchAll() as $row) {
                $granted[$row['name']] = true;
            }
        }

        // Per-user overrides win over role grants (granted=0 denies even if role allows).
        $stmt = $pdo->prepare(
            'SELECT p.name, up.granted FROM user_permissions up
             JOIN permissions p ON p.id = up.permission_id
             WHERE up.user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        foreach ($stmt->fetchAll() as $row) {
            if ((int) $row['granted'] === 1) {
                $granted[$row['name']] = true;
            } else {
                unset($granted[$row['name']]);
            }
        }

        self::$cache[$userId] = $granted;
        return $granted;
    }

    /**
     * All permission strings the system recognises (used by RoleController).
     * Read from the permissions table so the catalog always matches the DB;
     * falls back to the built-in list when the table is unavailable/empty.
     */
    public static function allPermissions(): array
    {
        try {
            $rows = Database::pdo()
                ->query('SELECT name FROM permissions ORDER BY id ASC')
                ->fetchAll();
            $names = array_column($rows, 'name');
            if ($names) {
                return $names;
            }
        } catch (\Throwable) {
            // fall through to the built-in list
        }
        return [
            'dashboard.view',
            'medicines.view', 'medicines.create', 'medicines.edit', 'medicines.delete',
            'inventory.view', 'inventory.adjust', 'inventory.transfer',
            'purchases.view', 'purchases.create', 'purchases.edit', 'purchases.delete', 'purchases.return',
            'sales.view', 'sales.create', 'sales.edit', 'sales.return',
            'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
            'suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete',
            'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete',
            'employees.view', 'employees.manage',
            'prescriptions.view', 'prescriptions.create',
            'reports.view', 'reports.export',
            'settings.view', 'settings.manage',
            'users.view', 'users.manage',
            'audit_logs.view',
        ];
    }
}
