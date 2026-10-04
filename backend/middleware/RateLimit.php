<?php
declare(strict_types=1);

namespace Pharmacy\Middleware;

use Pharmacy\Helpers\ApiException;

/**
 * Simple file/IP-based rate limiter. Good enough for login brute-force
 * protection and abusive clients on shared hosting (no Redis needed).
 */
final class RateLimit
{
    /**
     * @throws ApiException 429 when the limit is exceeded.
     */
    public static function check(array $request, int $maxAttempts, int $windowSeconds = 60): void
    {
        $dir = BACKEND_PATH . '/logs/ratelimit';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return; // Fail open if we cannot persist counters.
        }

        $key  = md5(($request['ip'] ?? '0.0.0.0') . '|' . ($request['path'] ?? '/'));
        $file = $dir . '/' . $key . '.json';
        $now  = time();

        $data = ['count' => 0, 'reset' => $now + $windowSeconds];
        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded) && ($decoded['reset'] ?? 0) > $now) {
                $data = $decoded;
            }
        }

        $data['count']++;
        @file_put_contents($file, json_encode($data), LOCK_EX);

        if ($data['count'] > $maxAttempts) {
            $retryAfter = max(1, $data['reset'] - $now);
            header('Retry-After: ' . $retryAfter);
            throw new ApiException(
                "Too many requests. Try again in {$retryAfter} seconds.",
                429
            );
        }
    }
}
