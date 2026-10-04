<?php
declare(strict_types=1);

namespace Pharmacy\Middleware;

use Pharmacy\Config\Config;
use Pharmacy\Helpers\ApiException;

/**
 * CSRF protection for session-based flows (AUTH_MODE=session|both).
 * JWT Bearer flows are immune to CSRF (no ambient credentials), so this
 * middleware only enforces when the request was authenticated via session.
 */
final class Csrf
{
    public static function handle(array $request): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Only enforce for session-authenticated requests.
        if (empty($_SESSION['user_id'])) {
            return;
        }

        $safe = ['GET', 'HEAD', 'OPTIONS'];
        if (in_array($request['method'], $safe, true)) {
            return;
        }

        $sent     = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($request['body']['_csrf'] ?? '');
        $expected = $_SESSION['csrf_token'] ?? '';
        if ($sent === '' || $expected === '' || !hash_equals((string) $expected, (string) $sent)) {
            throw new ApiException('Invalid CSRF token.', 419);
        }
    }

    /** Issue (or reuse) the session CSRF token — returned on login/me. */
    public static function token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function modeRequiresCsrf(): bool
    {
        return in_array(Config::getString('AUTH_MODE', 'jwt'), ['session', 'both'], true);
    }
}
