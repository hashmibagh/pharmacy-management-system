<?php
declare(strict_types=1);

namespace Pharmacy\Config;

/**
 * Tiny .env loader — no external dependency (do NOT pull in vlucas/phpdotenv
 * so the backend keeps working on minimal shared hosting / KSWEB).
 *
 * Real environment variables always win over .env file values.
 */
final class Config
{
    /** @var array<string, string> */
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(string $envPath): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (is_file($envPath) && is_readable($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                // Support "export KEY=value" style lines.
                if (str_starts_with($line, 'export ')) {
                    $line = trim(substr($line, 7));
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key   = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                // Strip surrounding quotes.
                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last  = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                        if ($first === '"') {
                            $value = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $value);
                        }
                    }
                }
                // Inline comments for unquoted values: KEY=value # comment
                if (!str_contains($value, '"') && !str_contains($value, "'") && str_contains($value, ' #')) {
                    $value = trim(substr($value, 0, strpos($value, ' #')));
                }
                if ($key !== '' && preg_match('/^[A-Z0-9_]+$/i', $key)) {
                    self::$data[$key] = $value;
                }
            }
        }

        // Real environment wins.
        foreach ($_ENV as $k => $v) {
            if (is_string($v) || is_numeric($v)) {
                self::$data[(string) $k] = (string) $v;
            }
        }
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Z][A-Z0-9_]+$/', $k) && (is_string($v) || is_numeric($v))) {
                self::$data[$k] = (string) $v;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function getString(string $key, string $default = ''): string
    {
        $v = self::get($key, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Absolute upload root; falls back to backend/uploads. */
    public static function uploadPath(): string
    {
        $p = self::getString('UPLOAD_PATH', '');
        if ($p !== '') {
            return rtrim($p, '/\\');
        }
        return BACKEND_PATH . '/uploads';
    }

    public static function uploadMaxBytes(): int
    {
        return self::getInt('UPLOAD_MAX_SIZE_MB', 5) * 1024 * 1024;
    }
}
