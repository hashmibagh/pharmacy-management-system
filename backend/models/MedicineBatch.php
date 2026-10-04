<?php
declare(strict_types=1);

namespace Pharmacy\Models;

class MedicineBatch extends BaseModel
{
    protected static bool $softDeletes = true;
    protected static string $table = 'medicine_batches';

    /** Batches for a medicine ordered FIFO (earliest expiry first). */
    public static function fifo(int $medicineId): array
    {
        return static::where(
            'medicine_id = :mid AND quantity > 0 AND deleted_at IS NULL',
            [':mid' => $medicineId],
            'expiry_date ASC, id ASC'
        );
    }

    public static function expiring(int $days): array
    {
        return static::raw(
            'SELECT b.*, b.quantity AS qty, b.batch_number AS batch_no,
                    b.minimum_stock AS min_stock, m.medicine_name
             FROM medicine_batches b
             JOIN medicines m ON m.id = b.medicine_id
             WHERE b.quantity > 0 AND b.expiry_date IS NOT NULL
               AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)
               AND b.deleted_at IS NULL
             ORDER BY b.expiry_date ASC',
            [':days' => $days]
        );
    }

    public static function expired(): array
    {
        return static::raw(
            'SELECT b.*, b.quantity AS qty, b.batch_number AS batch_no,
                    b.minimum_stock AS min_stock, m.medicine_name
             FROM medicine_batches b
             JOIN medicines m ON m.id = b.medicine_id
             WHERE b.quantity > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()
               AND b.deleted_at IS NULL
             ORDER BY b.expiry_date ASC'
        );
    }
}
