<?php
declare(strict_types=1);

namespace Pharmacy\Services;

use Pharmacy\Models\Medicine;
use Pharmacy\Models\MedicineBatch;
use Pharmacy\Models\Notification;

/**
 * Creates in-app notifications (low stock, expiring/expired batches,
 * payments due). Called from controllers and can also run on a schedule.
 * Dedupe: one unread notification per (type, title) at a time.
 */
final class NotificationService
{
    public static function create(
        ?int $userId,
        string $type,
        string $title,
        string $message,
        ?string $link = null
    ): void {
        // Dedupe against an existing unread notification with the same type+title.
        $exists = Notification::rawOne(
            'SELECT id FROM notifications
             WHERE type = :t AND title = :ti AND is_read = 0 LIMIT 1',
            [':t' => $type, ':ti' => substr($title, 0, 255)]
        );
        if ($exists) {
            return;
        }

        Notification::create([
            'user_id'    => $userId,
            'type'       => $type,
            'title'      => substr($title, 0, 255),
            'message'    => substr($message, 0, 1000),
            'link'       => $link,
            'is_read'    => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Notify about medicines at/below reorder level. Returns count created. */
    public static function checkLowStock(?int $userId = null): int
    {
        $rows = Medicine::raw(
            'SELECT m.id, m.medicine_name,
                    COALESCE(SUM(b.quantity),0) AS stock,
                    COALESCE(MIN(b.minimum_stock),0) AS min_stock
             FROM medicines m
             LEFT JOIN medicine_batches b ON b.medicine_id = m.id AND b.deleted_at IS NULL
             WHERE m.status = \'active\' AND m.deleted_at IS NULL
             GROUP BY m.id, m.medicine_name
             HAVING stock > 0 AND min_stock > 0 AND stock <= min_stock'
        );
        foreach ($rows as $m) {
            self::create(
                $userId,
                'low_stock',
                'Low stock: ' . $m['medicine_name'],
                sprintf(
                    '%s is at %s units (reorder level %s).',
                    $m['medicine_name'], $m['stock'], $m['min_stock']
                )
            );
        }
        return count($rows);
    }

    /** Notify about batches expiring within $days (and already expired). */
    public static function checkExpiry(int $days = 90, ?int $userId = null): int
    {
        $rows = MedicineBatch::raw(
            'SELECT b.id, b.batch_number, b.quantity, b.expiry_date, m.medicine_name
             FROM medicine_batches b JOIN medicines m ON m.id = b.medicine_id
             WHERE b.quantity > 0 AND b.expiry_date IS NOT NULL
               AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)
               AND b.deleted_at IS NULL
             ORDER BY b.expiry_date ASC',
            [':days' => $days]
        );
        foreach ($rows as $b) {
            $expired = $b['expiry_date'] < date('Y-m-d');
            self::create(
                $userId,
                'expiry',
                ($expired ? 'Expired: ' : 'Expiring soon: ') . $b['medicine_name'],
                sprintf(
                    'Batch %s of %s (%s units) %s on %s.',
                    $b['batch_number'], $b['medicine_name'], $b['quantity'],
                    $expired ? 'expired' : 'expires', $b['expiry_date']
                )
            );
        }
        return count($rows);
    }
}
