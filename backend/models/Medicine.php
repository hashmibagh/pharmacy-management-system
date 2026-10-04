<?php
declare(strict_types=1);

namespace Pharmacy\Models;

class Medicine extends BaseModel
{
    protected static bool $softDeletes = true;
    protected static string $table = 'medicines';

    /**
     * Stock lives ONLY in medicine_batches.quantity — there is no
     * There is no per-medicine stock column. This correlated subselect is the
     * canonical "current stock" expression for a medicine row aliased `m`.
     */
    public const STOCK_SQL = '(SELECT COALESCE(SUM(b2.quantity),0) FROM medicine_batches b2 WHERE b2.medicine_id = m.id AND b2.deleted_at IS NULL)';

    public const MIN_STOCK_SQL = '(SELECT COALESCE(MIN(b3.minimum_stock),0) FROM medicine_batches b3 WHERE b3.medicine_id = m.id AND b3.deleted_at IS NULL)';

    public const MAX_STOCK_SQL = '(SELECT COALESCE(MIN(b3.maximum_stock),0) FROM medicine_batches b3 WHERE b3.medicine_id = m.id AND b3.deleted_at IS NULL)';

    public const SALE_PRICE_SQL = '(SELECT b4.sale_price FROM medicine_batches b4 WHERE b4.medicine_id = m.id AND b4.deleted_at IS NULL ORDER BY b4.id DESC LIMIT 1)';

    public const PURCHASE_PRICE_SQL = '(SELECT b4.purchase_price FROM medicine_batches b4 WHERE b4.medicine_id = m.id AND b4.deleted_at IS NULL ORDER BY b4.id DESC LIMIT 1)';

    public static function findWithRelations(int $id): ?array
    {
        $row = static::rawOne(
            'SELECT m.*, m.medicine_name AS name, m.brand_name AS brand, m.packing AS unit,
                    c.name AS category_name, mf.name AS manufacturer_name,'
                . self::STOCK_SQL . ' AS stock,'
                . self::MIN_STOCK_SQL . ' AS min_stock,'
                . self::MAX_STOCK_SQL . ' AS max_stock,'
                . self::SALE_PRICE_SQL . ' AS sale_price,'
                . self::PURCHASE_PRICE_SQL . ' AS purchase_price
             FROM medicines m
             LEFT JOIN medicine_categories c ON c.id = m.category_id
             LEFT JOIN manufacturers mf ON mf.id = m.manufacturer_id
             WHERE m.id = :id AND m.deleted_at IS NULL LIMIT 1',
            [':id' => $id]
        );
        return $row ? self::withCategoryObject($row) : null;
    }

    public static function searchPaginate(
        int $page, int $perPage, string $search = '', array $filters = []
    ): array {
        $where  = ['m.deleted_at IS NULL'];
        $params = [];

        if ($search !== '') {
            $where[] = '(m.medicine_name LIKE :s OR m.generic_name LIKE :s OR m.barcode LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        foreach (['category_id', 'manufacturer_id'] as $f) {
            if (!empty($filters[$f])) {
                $where[] = "m.{$f} = :{$f}";
                $params[":{$f}"] = $filters[$f];
            }
        }
        // status filter (accepts legacy is_active=1/0 as well).
        $status = $filters['status'] ?? null;
        if (($status === null || $status === '') && isset($filters['is_active']) && $filters['is_active'] !== '') {
            $status = (int) $filters['is_active'] === 1 ? 'active' : 'inactive';
        }
        if ($status !== null && $status !== '') {
            $where[] = 'm.status = :status';
            $params[':status'] = $status;
        }
        // POS sends in_stock=1.
        if (!empty($filters['in_stock'])) {
            $where[] = self::STOCK_SQL . ' > 0';
        }
        // Medicines page stock filter: low | out | expiring.
        $stock = $filters['stock'] ?? '';
        if ($stock === 'low') {
            $where[] = self::STOCK_SQL . ' > 0 AND ' . self::STOCK_SQL . ' <= ' . self::MIN_STOCK_SQL;
        } elseif ($stock === 'out') {
            $where[] = self::STOCK_SQL . ' <= 0';
        } elseif ($stock === 'expiring') {
            $where[] = 'EXISTS (SELECT 1 FROM medicine_batches b5 WHERE b5.medicine_id = m.id
                                 AND b5.deleted_at IS NULL AND b5.quantity > 0
                                 AND b5.expiry_date IS NOT NULL
                                 AND b5.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY))';
        }

        $w = implode(' AND ', $where);
        $total = static::rawOne(
            "SELECT COUNT(*) AS c FROM medicines m WHERE {$w}",
            $params
        )['c'] ?? 0;

        $offset = ($page - 1) * $perPage;
        $data = static::raw(
            'SELECT m.*, m.medicine_name AS name, m.brand_name AS brand, m.packing AS unit,
                    c.name AS category_name, mf.name AS manufacturer_name,'
                . self::STOCK_SQL . ' AS stock,'
                . self::MIN_STOCK_SQL . ' AS min_stock,'
                . self::MAX_STOCK_SQL . ' AS max_stock,'
                . self::SALE_PRICE_SQL . ' AS sale_price,'
                . self::PURCHASE_PRICE_SQL . ' AS purchase_price
             FROM medicines m
             LEFT JOIN medicine_categories c ON c.id = m.category_id
             LEFT JOIN manufacturers mf ON mf.id = m.manufacturer_id
             WHERE ' . $w . '
             ORDER BY m.medicine_name ASC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        $data = array_map([self::class, 'withCategoryObject'], $data);

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => (int) $total,
                'last_page'    => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    /** Shape the joined category as the object the frontend reads (r.category.name). */
    private static function withCategoryObject(array $row): array
    {
        $row['category'] = [
            'id'   => $row['category_id'] ?? null,
            'name' => $row['category_name'] ?? null,
        ];
        return $row;
    }
}
