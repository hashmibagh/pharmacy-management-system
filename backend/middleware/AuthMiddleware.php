<?php
declare(strict_types=1);

namespace Pharmacy\Middleware;

use Pharmacy\Config\Config;
use Pharmacy\Config\Database;
use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Auth;

/**
 * Attaches the authenticated user to $request['user'].
 *
 * AUTH_MODE=jwt     → Bearer access token (Authorization header).
 * AUTH_MODE=session → PHP session (same-origin web flows).
 * AUTH_MODE=both    → tries Bearer first, falls back to session.
 *
 * The attached user array contains: id, name, email, role_id, role_name,
 * is_super_admin (bool). Password hashes are never attached.
 */
final class AuthMiddleware
{
    public static function handle(array &$request): void
    {
        $mode = Config::getString('AUTH_MODE', 'jwt');
        $user = null;

        if ($mode === 'jwt' || $mode === 'both') {
            $user = self::userFromBearer();
        }
        if ($user === null && ($mode === 'session' || $mode === 'both')) {
            $user = self::userFromSession();
        }

        if ($user === null) {
            throw new ApiException('Unauthenticated.', 401);
        }

        $request['user'] = $user;
    }

    private static function userFromBearer(): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if (!str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }
        $token  = trim(substr($header, 7));
        $claims = Auth::verifyAccessToken($token);
        if (!$claims) {
            return null;
        }
        return self::loadUser((int) $claims['sub']);
    }

    private static function userFromSession(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => true,
            ]);
        }
        $uid = $_SESSION['user_id'] ?? null;
        if (!$uid) {
            return null;
        }
        return self::loadUser((int) $uid);
    }

    /** Create a session login (used by AuthController when AUTH_MODE includes session). */
    public static function loginViaSession(int $userId): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => true,
            ]);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    public static function logoutSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    private static function loadUser(int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.name, u.email, u.role_id, u.status, r.name AS role_name,
                    (r.name = \'Super Admin\') AS is_super_admin
             FROM users u LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user || ($user['status'] ?? '') !== 'active') {
            return null;
        }
        $user['is_super_admin'] = (int) ($user['is_super_admin'] ?? 0) === 1;
        unset($user['password_hash']);
        return $user;
    }
}
