<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\Validator;
use Pharmacy\Models\AuditLog;

/**
 * Shared controller conveniences. Permissions are enforced by
 * PermissionMiddleware via api/routes.php — never inline.
 */
abstract class BaseController
{
    /** @return array<string, mixed> */
    protected function user(array $request): array
    {
        return $request['user'] ?? [];
    }

    protected function uid(array $request): int
    {
        return (int) ($request['user']['id'] ?? 0);
    }

    /** @return array<string, mixed> */
    protected function body(array $request): array
    {
        return is_array($request['body']) ? $request['body'] : [];
    }

    /** @return array<string, mixed> */
    protected function query(array $request): array
    {
        return is_array($request['query']) ? $request['query'] : [];
    }

    protected function param(array $request, string $name, mixed $default = null): mixed
    {
        return $request['params'][$name] ?? $default;
    }

    protected function audit(
        array $request,
        string $action,
        string $module,
        int|string|null $recordId,
        mixed $old = null,
        mixed $new = null
    ): void {
        AuditLog::record(
            $this->uid($request) ?: null,
            $action,
            $module,
            $recordId,
            $old,
            $new,
            $request['ip'] ?? '0.0.0.0',
            $request['user_agent'] ?? ''
        );
    }

    /** Whitelist + trim string fields from input. */
    protected function filtered(array $data, array $allowed): array
    {
        $out = Validator::only($data, $allowed);
        foreach ($out as $k => $v) {
            if (is_string($v)) {
                $out[$k] = trim($v);
            }
        }
        return $out;
    }
}
