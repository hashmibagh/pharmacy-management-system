<?php
declare(strict_types=1);

namespace Pharmacy\Models;

class User extends BaseModel
{
    protected static string $table = 'users';

    public static function findByEmail(string $email): ?array
    {
        return static::findBy('email', $email);
    }

    public static function withRole(int $id): ?array
    {
        // Super Admin is identified by role NAME (roles has no is_super_admin column).
        return static::rawOne(
            'SELECT u.*, r.name AS role_name, (r.name = \'Super Admin\') AS is_super_admin
             FROM users u LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id LIMIT 1',
            [':id' => $id]
        );
    }

    /** Strip sensitive fields before returning a user row to clients. */
    public static function public(array $user): array
    {
        unset($user['password_hash'], $user['remember_token']);
        return $user;
    }
}
