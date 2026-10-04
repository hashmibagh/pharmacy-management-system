<?php
declare(strict_types=1);

namespace Pharmacy\Models;

class Purchase extends BaseModel
{
    protected static bool $softDeletes = true;
    protected static string $table = 'purchases';

    public static function findDetailed(int $id): ?array
    {
        $p = static::rawOne(
            'SELECT p.*,
                    p.invoice_number AS invoice_no,
                    s.name AS supplier_name, s.phone AS supplier_phone, u.name AS created_by_name
             FROM purchases p
             LEFT JOIN suppliers s ON s.id = p.supplier_id
             LEFT JOIN users u ON u.id = p.created_by
             WHERE p.id = :id LIMIT 1',
            [':id' => $id]
        );
        if (!$p) {
            return null;
        }
        $p['items'] = static::raw(
            'SELECT pi.*, pi.quantity AS qty,  pi.total AS amount,
                    m.medicine_name, m.medicine_name AS name,
                    b.batch_number, b.batch_number AS batch_no
             FROM purchase_items pi
             LEFT JOIN medicines m ON m.id = pi.medicine_id
             LEFT JOIN medicine_batches b ON b.id = pi.batch_id
             WHERE pi.purchase_id = :pid ORDER BY pi.id ASC',
            [':pid' => $id]
        );
        $p['payments'] = static::raw(
            'SELECT *, payment_date AS date, reference AS reference_no
             FROM purchase_payments WHERE purchase_id = :pid ORDER BY payment_date ASC',
            [':pid' => $id]
        );
        return $p;
    }

    public static function paidTotal(int $purchaseId): float
    {
        $row = static::rawOne(
            'SELECT COALESCE(SUM(amount),0) AS t FROM purchase_payments WHERE purchase_id = :pid',
            [':pid' => $purchaseId]
        );
        return (float) ($row['t'] ?? 0);
    }
}
