<?php
declare(strict_types=1);

namespace Pharmacy\Helpers;

/**
 * Consistent JSON envelope for every API response.
 *
 * Success: {"success":true,"message":"...","data":{...}}
 * Error:   {"success":false,"message":"...","errors":{...}}
 */
final class Response
{
    public static function json(mixed $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @param mixed $data
     */
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): void
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data ?? new \stdClass(),
        ], $status);
    }

    /**
     * @param array<string, mixed> $errors field-level validation errors
     */
    public static function error(string $message = 'Error', int $status = 400, array $errors = []): void
    {
        self::json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors ?: new \stdClass(),
        ], $status);
    }

    /** 422 helper for validation failures. */
    public static function validation(array $errors, string $message = 'Validation failed'): void
    {
        self::error($message, 422, $errors);
    }
}
