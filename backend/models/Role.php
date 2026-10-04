<?php
declare(strict_types=1);

namespace Pharmacy\Models;

class Role extends BaseModel
{
    protected static string $table = 'roles';

    /** Permission NAMES granted to the role (via the normalized permissions table). */
    public static function permissions(int $roleId): array
    {
        $rows = static::raw(
            'SELECT p.name FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :rid ORDER BY p.name ASC',
            [':rid' => $roleId]
        );
        return array_column($rows, 'name');
    }

    /** Resolve permission names to their ids via the permissions table. */
    public static function permissionIds(array $names): array
    {
        $names = array_values(array_unique($names));
        if (!$names) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $stmt = static::db()->prepare(
            "SELECT id, name FROM permissions WHERE name IN ({$placeholders})"
        );
        $stmt->execute($names);
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[$r['name']] = (int) $r['id'];
        }
        $ids = [];
        foreach ($names as $n) {
            if (isset($map[$n])) {
                $ids[] = $map[$n];
            }
        }
        return $ids;
    }

    /** Replace the role's permission set (used by RoleController). */
    public static function syncPermissions(int $roleId, array $permissions): void
    {
        $pdo = static::db();
        $del = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :rid');
        $del->execute([':rid' => $roleId]);
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:rid, :pid)'
        );
        foreach (self::permissionIds($permissions) as $pid) {
            $ins->execute([':rid' => $roleId, ':pid' => $pid]);
        }
    }
}
