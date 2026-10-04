<?php
declare(strict_types=1);

namespace Pharmacy\Models;

class Sale extends BaseModel
{
    protected static bool $softDeletes = true;
    protected static string $table = 'sales';

    public static function findDetailed(int $id): ?array
    {
        $s = static::rawOne(
            'SELECT s.*,
                    s.invoice_number AS invoice_no,
                    c.name AS customer_name, c.phone AS customer_phone, u.name AS created_by_name
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             LEFT JOIN users u ON u.id = s.created_by
             WHERE s.id = :id LIMIT 1',
            [':id' => $id]
        );
        if (!$s) {
            return null;
        }
        $s['items'] = static::raw(
            'SELECT si.*, si.quantity AS qty, si.sale_price AS unit_price, si.total AS amount,
                    m.medicine_name, m.medicine_name AS name,
                    b.batch_number, b.batch_number AS batch_no
             FROM sale_items si
             LEFT JOIN medicines m ON m.id = si.medicine_id
             LEFT JOIN medicine_batches b ON b.id = si.batch_id
             WHERE si.sale_id = :sid ORDER BY si.id ASC',
            [':sid' => $id]
        );
        $s['payments'] = static::raw(
            'SELECT *, payment_date AS date FROM sale_payments WHERE sale_id = :sid ORDER BY payment_date ASC',
            [':sid' => $id]
        );
        return $s;
    }

    public static function paidTotal(int $saleId): float
    {
        $row = static::rawOne(
            'SELECT COALESCE(SUM(amount),0) AS t FROM sale_payments WHERE sale_id = :sid',
            [':sid' => $saleId]
        );
        return (float) ($row['t'] ?? 0);
    }
}
