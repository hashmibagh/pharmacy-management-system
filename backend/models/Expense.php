<?php
declare(strict_types=1);

namespace Pharmacy\Models;

class Expense extends BaseModel
{
    protected static bool $softDeletes = true;
    protected static string $table = 'expenses';

    public static function withCategory(
        int $page, int $perPage, ?string $from, ?string $to, ?int $categoryId, ?string $search = null
    ): array {
        $where = ['e.deleted_at IS NULL'];
        $params = [];
        if ($from) {
            $where[] = 'e.expense_date >= :from';
            $params[':from'] = $from;
        }
        if ($to) {
            $where[] = 'e.expense_date <= :to';
            $params[':to'] = $to;
        }
        if ($categoryId) {
            $where[] = 'e.expense_category_id = :cat';
            $params[':cat'] = $categoryId;
        }
        if ($search !== null && $search !== '') {
            $where[] = 'e.description LIKE :s';
            $params[':s'] = '%' . $search . '%';
        }
        $w = implode(' AND ', $where);
        $total = static::rawOne("SELECT COUNT(*) AS c FROM expenses e WHERE {$w}", $params)['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = static::raw(
            "SELECT e.*, e.expense_date AS date, ec.name AS category_name, u.name AS created_by_name
             FROM expenses e
             LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
             LEFT JOIN users u ON u.id = e.created_by
             WHERE {$w} ORDER BY e.expense_date DESC, e.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        $data = array_map([self::class, 'shape'], $data);
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page, 'per_page' => $perPage,
                'total' => (int) $total, 'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * Present one expense row with the keys the frontend consumes:
     * title / note (split from description), date (alias of expense_date).
     */
    public static function shape(array $row): array
    {
        $desc = (string) ($row['description'] ?? '');
        $parts = preg_split("/\r?\n/", $desc, 2);
        $row['title'] = trim($parts[0] ?? '');
        $row['note']  = trim($parts[1] ?? '');
        $row['date']  = $row['expense_date'] ?? null;
        return $row;
    }
}
