<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Config\Config;
use Pharmacy\Config\Database;
use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Auth;
use Pharmacy\Helpers\FileUpload;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Middleware\AuthMiddleware;
use Pharmacy\Middleware\Csrf;
use Pharmacy\Middleware\PermissionMiddleware;
use Pharmacy\Models\User;

class AuthController extends BaseController
{
    public function login(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'email'    => 'required|email|max:190',
            'password' => 'required|string|min:1|max:255',
        ]);

        $user = User::findByEmail(strtolower(trim($b['email'])));
        if (!$user || !password_verify((string) $b['password'], (string) ($user['password_hash'] ?? ''))) {
            // Same message either way — no user enumeration.
            throw new ApiException('Invalid email or password.', 401);
        }
        if (($user['status'] ?? '') !== 'active') {
            throw new ApiException('This account has been disabled.', 403);
        }

        // Transparent rehash when the algorithm/cost changes.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            User::update((int) $user['id'], ['password_hash' => password_hash((string) $b['password'], PASSWORD_DEFAULT)]);
        }

        $full = User::withRole((int) $user['id']) ?? $user;
        $accessToken  = Auth::issueAccessToken($full);
        $refreshToken = Auth::issueRefreshToken((int) $user['id'], $request['ip'], $request['user_agent']);
        Auth::setRefreshCookie($refreshToken);

        $csrfToken = null;
        if (in_array(Config::getString('AUTH_MODE', 'jwt'), ['session', 'both'], true)) {
            AuthMiddleware::loginViaSession((int) $user['id']);
            $csrfToken = Csrf::token();
        }

        $this->audit(array_merge($request, ['user' => ['id' => (int) $user['id']]]), 'LOGIN', 'auth', $user['id']);

        // Update last login.
        User::rawExec('UPDATE users SET last_login_at = NOW() WHERE id = :id', [':id' => $user['id']]);

        Response::success([
            'access_token'  => $accessToken,
            'token_type'    => 'Bearer',
            'expires_in'    => Config::getInt('JWT_ACCESS_TTL', 900),
            'refresh_token' => $refreshToken, // for non-cookie clients; cookie is also set
            'csrf_token'    => $csrfToken,
            'user'          => User::public($full),
        ], 'Login successful.');
    }

    public function logout(array $request): void
    {
        $raw = Auth::readRefreshToken($request);
        if ($raw) {
            Auth::revokeRefreshToken($raw);
        }
        Auth::clearRefreshCookie();
        if (in_array(Config::getString('AUTH_MODE', 'jwt'), ['session', 'both'], true)) {
            AuthMiddleware::logoutSession();
        }
        $this->audit($request, 'LOGOUT', 'auth', $this->uid($request));
        Response::success(null, 'Logged out successfully.');
    }

    public function me(array $request): void
    {
        $user = User::withRole($this->uid($request));
        if (!$user) {
            throw new ApiException('User not found.', 404);
        }
        $perms = !empty($user['is_super_admin'])
            ? PermissionMiddleware::allPermissions()
            : array_keys(PermissionMiddleware::grantedPermissions((int) $user['id'], (int) ($user['role_id'] ?? 0)));

        Response::success([
            'user'        => User::public($user),
            'permissions' => array_values($perms),
            'csrf_token'  => Csrf::modeRequiresCsrf() ? Csrf::token() : null,
        ]);
    }

    public function refresh(array $request): void
    {
        $raw = Auth::readRefreshToken($request);
        if (!$raw) {
            throw new ApiException('Refresh token missing.', 401);
        }
        $rotated = Auth::rotateRefreshToken($raw, $request['ip'], $request['user_agent']);
        if (!$rotated) {
            throw new ApiException('Invalid or expired refresh token.', 401);
        }
        Auth::setRefreshCookie($rotated['refresh_token']);
        Response::success([
            'access_token'  => $rotated['access_token'],
            'token_type'    => 'Bearer',
            'expires_in'    => Config::getInt('JWT_ACCESS_TTL', 900),
            'refresh_token' => $rotated['refresh_token'],
        ], 'Token refreshed.');
    }

    public function forgotPassword(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, ['email' => 'required|email|max:190']);
        $email = strtolower(trim($b['email']));

        $user = User::findByEmail($email);
        // Always respond the same way to avoid user enumeration.
        if ($user && ($user['status'] ?? '') === 'active') {
            $raw  = bin2hex(random_bytes(32));
            $pdo  = Database::pdo();
            $pdo->prepare('DELETE FROM password_resets WHERE email = :e')->execute([':e' => $email]);
            $pdo->prepare(
                'INSERT INTO password_resets (email, token_hash, expires_at, created_at)
                 VALUES (:e, :h, :exp, NOW())'
            )->execute([
                ':e'   => $email,
                ':h'   => hash('sha256', $raw),
                ':exp' => date('Y-m-d H:i:s', time() + 3600),
            ]);
            // In production this token is emailed. Expose it only in debug mode.
            $debugToken = Config::getBool('APP_DEBUG', false) ? $raw : null;
            Response::success(
                ['reset_token' => $debugToken],
                'If an account exists for this email, a password reset link has been sent.'
            );
            return;
        }
        Response::success(null, 'If an account exists for this email, a password reset link has been sent.');
    }

    public function resetPassword(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'token'    => 'required|string',
            'email'    => 'required|email|max:190',
            'password' => 'required|string|min:8|max:255',
        ]);

        $email = strtolower(trim($b['email']));
        $row = Database::pdo()->prepare(
            'SELECT * FROM password_resets WHERE email = :e AND token_hash = :h LIMIT 1'
        );
        $row->execute([':e' => $email, ':h' => hash('sha256', (string) $b['token'])]);
        $reset = $row->fetch();

        if (!$reset || strtotime($reset['expires_at']) < time()) {
            throw new ApiException('Invalid or expired reset token.', 422);
        }
        $user = User::findByEmail($email);
        if (!$user) {
            throw new ApiException('Invalid or expired reset token.', 422);
        }

        Database::beginTransaction();
        try {
            User::update((int) $user['id'], ['password_hash' => password_hash((string) $b['password'], PASSWORD_DEFAULT)]);
            Database::pdo()->prepare('DELETE FROM password_resets WHERE email = :e')->execute([':e' => $email]);
            Auth::revokeAllForUser((int) $user['id']); // kill all sessions
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'PASSWORD_RESET', 'auth', $user['id']);
        Response::success(null, 'Password has been reset. Please log in again.');
    }

    public function changePassword(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|max:255',
        ]);

        $user = User::find($this->uid($request));
        if (!$user || !password_verify((string) $b['current_password'], (string) ($user['password_hash'] ?? ''))) {
            throw new ApiException('Current password is incorrect.', 422);
        }

        User::update((int) $user['id'], ['password_hash' => password_hash((string) $b['new_password'], PASSWORD_DEFAULT)]);
        Auth::revokeAllForUser((int) $user['id']); // force re-login everywhere
        $this->audit($request, 'PASSWORD_CHANGED', 'auth', $user['id']);
        Response::success(null, 'Password changed. Please log in again.');
    }

    public function getProfile(array $request): void
    {
        $user = User::withRole($this->uid($request));
        if (!$user) {
            throw new ApiException('User not found.', 404);
        }
        Response::success(User::public($user));
    }

    public function updateProfile(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'name'  => 'required|string|max:150',
            'email' => 'required|email|max:190',
            'phone' => 'nullable|string|max:30',
        ]);

        $id = $this->uid($request);
        $existing = User::findByEmail(strtolower(trim($b['email'])));
        if ($existing && (int) $existing['id'] !== $id) {
            throw new ApiException('This email is already taken.', 422, ['email' => ['This email is already taken.']]);
        }

        $old = User::find($id);
        User::update($id, $this->filtered($b, ['name', 'email', 'phone']));
        $this->audit($request, 'PROFILE_UPDATED', 'auth', $id, $old ? User::public($old) : null, $b);
        Response::success(User::public(User::withRole($id) ?? []), 'Profile updated.');
    }

    public function uploadPhoto(array $request): void
    {
        $file = $request['files']['photo'] ?? null;
        if (!$file) {
            throw new ApiException('No photo uploaded. Use the "photo" field.', 422);
        }
        [$exts, $mimes] = FileUpload::imageRules();
        $path = FileUpload::store($file, 'user', 'users', $exts, $mimes);

        $id  = $this->uid($request);
        $old = User::find($id);
        User::update($id, ['avatar' => $path]);
        FileUpload::delete($old['avatar'] ?? null);

        $this->audit($request, 'PROFILE_PHOTO_UPDATED', 'auth', $id);
        Response::success(['avatar' => $path], 'Profile photo updated.');
    }
}
