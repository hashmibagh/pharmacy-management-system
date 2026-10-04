<?php
declare(strict_types=1);

namespace Pharmacy\Helpers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Pharmacy\Config\Config;
use Pharmacy\Config\Database;

/**
 * JWT auth: short-lived access tokens (default 15 min) + rotating refresh
 * tokens (default 7 days). Refresh tokens are stored SHA-256 hashed in the
 * `refresh_tokens` table (see database/migrations.sql) so a DB leak does not
 * expose usable tokens. Rotation on every use; revocation on logout /
 * password change.
 *
 * Requires firebase/php-jwt (composer install). It is only a signing
 * library — no Firebase backend services are used anywhere.
 */
final class Auth
{
    private const ALGO = 'HS256';

    private static function jwtAvailable(): void
    {
        if (!class_exists(JWT::class)) {
            throw new ApiException(
                'JWT library is not installed. Run `composer install` in backend/.',
                500
            );
        }
    }

    private static function secret(): string
    {
        $s = Config::getString('JWT_SECRET', '');
        if (strlen($s) < 32) {
            throw new ApiException('JWT_SECRET is not configured (min 32 chars).', 500);
        }
        return $s;
    }

    /** Issue a short-lived access token for an authenticated user row. */
    public static function issueAccessToken(array $user): string
    {
        self::jwtAvailable();
        $now = time();
        $payload = [
            'iss'     => Config::getString('JWT_ISSUER', 'pharmacy-api'),
            'sub'     => (int) $user['id'],
            'iat'     => $now,
            'exp'     => $now + Config::getInt('JWT_ACCESS_TTL', 900),
            'role_id' => (int) ($user['role_id'] ?? 0),
            'type'    => 'access',
        ];
        return JWT::encode($payload, self::secret(), self::ALGO);
    }

    /**
     * Issue a refresh token; stores only its SHA-256 hash.
     * Returns the RAW token (send once to the client, never store raw).
     */
    public static function issueRefreshToken(int $userId, string $ip, string $userAgent): string
    {
        $raw  = bin2hex(random_bytes(48)); // 96 hex chars
        $hash = hash('sha256', $raw);
        $ttl  = Config::getInt('JWT_REFRESH_TTL', 604800);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, ip_address, user_agent, created_at)
             VALUES (:uid, :hash, :exp, :ip, :ua, NOW())'
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':hash' => $hash,
            ':exp'  => date('Y-m-d H:i:s', time() + $ttl),
            ':ip'   => substr($ip, 0, 45),
            ':ua'   => substr($userAgent, 0, 255),
        ]);

        return $raw;
    }

    /** Verify a Bearer access token; returns claims array or null. */
    public static function verifyAccessToken(string $token): ?array
    {
        self::jwtAvailable();
        try {
            $decoded = JWT::decode($token, new Key(self::secret(), self::ALGO));
        } catch (\Throwable) {
            return null;
        }
        $claims = (array) $decoded;
        if (($claims['type'] ?? '') !== 'access') {
            return null;
        }
        return $claims;
    }

    /**
     * Rotate a refresh token: validates, revokes the old row, issues a new pair.
     * @return array{user_id:int, access_token:string, refresh_token:string}|null
     */
    public static function rotateRefreshToken(string $rawToken, string $ip, string $userAgent): ?array
    {
        $hash = hash('sha256', $rawToken);
        $pdo  = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT * FROM refresh_tokens WHERE token_hash = :hash AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch();

        if (!$row || strtotime($row['expires_at']) < time()) {
            return null;
        }

        // Load user (must still exist & be active).
        $u = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $u->execute([':id' => $row['user_id']]);
        $user = $u->fetch();
        if (!$user || ($user['status'] ?? 'active') !== 'active') {
            return null;
        }

        // Rotate inside a transaction: revoke old, insert new.
        try {
            $pdo->beginTransaction();
            $revoke = $pdo->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = :id');
            $revoke->execute([':id' => $row['id']]);
            $newRaw = self::issueRefreshToken((int) $user['id'], $ip, $userAgent);
            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return null;
        }

        return [
            'user_id'       => (int) $user['id'],
            'access_token'  => self::issueAccessToken($user),
            'refresh_token' => $newRaw,
        ];
    }

    public static function revokeRefreshToken(string $rawToken): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = :hash'
        );
        $stmt->execute([':hash' => hash('sha256', $rawToken)]);
    }

    /** Revoke ALL refresh tokens for a user (logout everywhere / password change). */
    public static function revokeAllForUser(int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = :uid AND revoked_at IS NULL'
        );
        $stmt->execute([':uid' => $userId]);
    }

    /** Set the refresh token as an HTTP-only cookie (secure in production). */
    public static function setRefreshCookie(string $rawToken): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || Config::getString('APP_ENV') === 'production';
        setcookie('refresh_token', $rawToken, [
            'expires'  => time() + Config::getInt('JWT_REFRESH_TTL', 604800),
            'path'     => '/api/auth',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public static function clearRefreshCookie(): void
    {
        setcookie('refresh_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/api/auth',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /** Pull refresh token from cookie first, then JSON body / header fallback. */
    public static function readRefreshToken(array $request): ?string
    {
        if (!empty($_COOKIE['refresh_token'])) {
            return (string) $_COOKIE['refresh_token'];
        }
        if (!empty($request['body']['refresh_token'])) {
            return (string) $request['body']['refresh_token'];
        }
        return null;
    }
}
