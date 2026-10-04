<?php
declare(strict_types=1);

namespace Pharmacy\Config;

use PDO;
use PDOException;
use Pharmacy\Helpers\ApiException;

/**
 * PDO singleton for MySQL/MariaDB. Prepared statements are used everywhere
 * (ATTR_EMULATE_PREPARES is off) so user input is never concatenated into SQL.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Config::getString('DB_HOST', '127.0.0.1');
        $port = Config::getInt('DB_PORT', 3306);
        $name = Config::getString('DB_NAME', 'pharmacy_db');
        $user = Config::getString('DB_USER', 'root');
        $pass = Config::getString('DB_PASSWORD', '');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // Never leak DSN/credentials to the client.
            throw new ApiException('Database connection failed', 500);
        }

        return self::$pdo;
    }

    public static function beginTransaction(): void
    {
        self::pdo()->beginTransaction();
    }

    public static function commit(): void
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    public static function rollBack(): void
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /** Reset the singleton (useful for long-running workers/tests). */
    public static function disconnect(): void
    {
        self::$pdo = null;
    }
}
