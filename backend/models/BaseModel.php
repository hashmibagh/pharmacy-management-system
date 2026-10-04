<?php
declare(strict_types=1);

namespace Pharmacy\Models;

use Pharmacy\Config\Database;
use PDO;

/**
 * Thin PDO base model. All queries use prepared statements with bound
 * parameters — subclasses only ever supply the table name and, where
 * needed, bespoke finder methods.
 */
abstract class BaseModel
{
    protected static string $table = '';
    protected static string $pk = 'id';

    /**
     * Set to true in models whose table has a `deleted_at` column.
     * When true, find()/findBy()/where()/count() automatically exclude
     * soft-deleted rows and delete() performs a soft delete instead of
     * a hard DELETE.
     */
    protected static bool $softDeletes = false;

    protected static function db(): PDO
    {
        return Database::pdo();
    }

    /** Appends the soft-delete scope to a caller-supplied WHERE clause. */
    protected static function scope(string $where): string
    {
        if (static::$softDeletes) {
            return '(`deleted_at` IS NULL) AND (' . $where . ')';
        }
        return $where;
    }

    protected static function table(): string
    {
        if (static::$table === '') {
            throw new \LogicException('Model table not defined: ' . static::class);
        }
        return static::$table;
    }

    public static function find(int|string $id): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT * FROM `' . static::table() . '` WHERE ' . static::scope('`' . static::$pk . '` = :id') . ' LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findBy(string $column, mixed $value): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT * FROM `' . static::table() . '` WHERE ' . static::scope('`' . $column . '` = :v') . ' LIMIT 1'
        );
        $stmt->execute([':v' => $value]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function where(string $where, array $params = [], string $order = '', ?int $limit = null, ?int $offset = null): array
    {
        $sql = 'SELECT * FROM `' . static::table() . '` WHERE ' . static::scope($where);
        if ($order !== '') {
            $sql .= ' ORDER BY ' . $order;
        }
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }
        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function count(string $where = '1=1', array $params = []): int
    {
        $stmt = static::db()->prepare('SELECT COUNT(*) AS c FROM `' . static::table() . '` WHERE ' . static::scope($where));
        $stmt->execute($params);
        return (int) ($stmt->fetch()['c'] ?? 0);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public static function paginate(int $page, int $perPage, string $where = '1=1', array $params = [], string $order = ''): array
    {
        $total  = static::count($where, $params);
        $offset = ($page - 1) * $perPage;
        $data   = static::where($where, $params, $order, $perPage, $offset);
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data keys must be code-defined column names
     * @return int|string inserted id
     */
    public static function create(array $data): int|string
    {
        $cols  = array_keys($data);
        $place = array_map(fn($c) => ':' . $c, $cols);
        $sql   = 'INSERT INTO `' . static::table() . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', $place) . ')';
        $stmt  = static::db()->prepare($sql);
        $params = [];
        foreach ($data as $k => $v) {
            $params[':' . $k] = $v;
        }
        $stmt->execute($params);
        return static::db()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public static function update(int|string $id, array $data): int
    {
        if (!$data) {
            return 0;
        }
        $sets   = [];
        $params = [':id' => $id];
        foreach ($data as $k => $v) {
            $sets[] = "`$k` = :$k";
            $params[":$k"] = $v;
        }
        $stmt = static::db()->prepare(
            'UPDATE `' . static::table() . '` SET ' . implode(', ', $sets) . ' WHERE `' . static::$pk . '` = :id'
        );
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Soft-deletes when the model opts in via $softDeletes, otherwise
     * performs a hard DELETE. Returns affected row count.
     */
    public static function delete(int|string $id): int
    {
        if (static::$softDeletes) {
            $stmt = static::db()->prepare(
                'UPDATE `' . static::table() . '` SET `deleted_at` = NOW() WHERE `' . static::$pk . '` = :id AND `deleted_at` IS NULL'
            );
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount();
        }
        $stmt = static::db()->prepare(
            'DELETE FROM `' . static::table() . '` WHERE `' . static::$pk . '` = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount();
    }

    /** Permanently removes a row, bypassing soft-delete. */
    public static function forceDelete(int|string $id): int
    {
        $stmt = static::db()->prepare(
            'DELETE FROM `' . static::table() . '` WHERE `' . static::$pk . '` = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount();
    }

    /** Run an arbitrary prepared SELECT. */
    public static function raw(string $sql, array $params = []): array
    {
        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function rawOne(string $sql, array $params = []): ?array
    {
        $rows = static::raw($sql, $params);
        return $rows[0] ?? null;
    }

    public static function rawExec(string $sql, array $params = []): int
    {
        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
