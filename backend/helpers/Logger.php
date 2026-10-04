<?php
declare(strict_types=1);

namespace Pharmacy\Helpers;

/**
 * Server-side file logger. Raw DB errors and stack traces go here —
 * they are NEVER sent to API clients.
 */
final class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $dir = BACKEND_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }
}
