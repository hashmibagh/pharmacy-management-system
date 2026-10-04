<?php
declare(strict_types=1);

namespace Pharmacy\Services;

use Pharmacy\Config\Database;
use Pharmacy\Helpers\ApiException;
use Pharmacy\Models\Medicine;
use Pharmacy\Models\MedicineBatch;
use Pharmacy\Models\StockTransaction;

/**
 * CENTRAL stock mutation service. Every stock change in the system
 * (purchases, sales, adjustments, transfers, returns, damage, opening
 * stock) MUST go through here so that an immutable stock_transactions
 * row is always written.
 *
 * Stock lives ONLY in medicine_batches.quantity (there is no
 * batch quantities only), so no denormalized recompute is needed.
 *
 * All methods assume the caller already opened a PDO transaction when
 * combining with other writes (sale/purchase creation, etc.).
 */
final class StockService
{
    // Transaction types (must match stock_transactions.transaction_type enum)
    public const TYPE_PURCHASE   = 'purchase';
    public const TYPE_SALE       = 'sale';
    public const TYPE_ADJUST     = 'adjust';
    public const TYPE_TRANSFER   = 'transfer';
    public const TYPE_RETURN     = 'return';
    public const TYPE_DAMAGED    = 'damaged';
    public const TYPE_OPENING    = 'opening';
    public const TYPE_VERIFIED   = 'verification';

    /**
     * Map (refType, direction) → valid stock_transactions.transaction_type enum value.
     */
    private static function transactionType(string $refType, string $direction): string
    {
        $in = $direction === 'in';
        return match ($refType) {
            self::TYPE_TRANSFER => $in ? 'transfer_in' : 'transfer_out',
            self::TYPE_RETURN   => $in ? 'return_in' : 'return_out',
            self::TYPE_ADJUST   => 'adjustment',
            self::TYPE_VERIFIED => 'verification',
            self::TYPE_PURCHASE => 'purchase',
            self::TYPE_SALE     => 'sale',
            self::TYPE_DAMAGED  => 'damaged',
            self::TYPE_OPENING  => 'opening',
            default             => 'adjustment',
        };
    }

    /**
     * Add stock to a batch (creates the batch row when $batchId is null).
     */
    public static function increase(
        int $medicineId,
        ?int $batchId,
        float $qty,
        string $refType,
        int|string $refId,
        int $userId,
        ?float $purchasePrice = null,
        ?string $batchNo = null,
        ?string $expiryDate = null,
        string $notes = ''
    ): int {
        self::assertPositiveQty($qty);
        $medicine = Medicine::find($medicineId);
        if (!$medicine) {
            throw new ApiException('Medicine not found.', 404);
        }

        if ($batchId) {
            $batch = MedicineBatch::find($batchId);
            if (!$batch || (int) $batch['medicine_id'] !== $medicineId) {
                throw new ApiException('Batch not found for this medicine.', 404);
            }
            MedicineBatch::rawExec(
                'UPDATE medicine_batches SET quantity = quantity + :q, updated_at = NOW() WHERE id = :id',
                [':q' => $qty, ':id' => $batchId]
            );
        } else {
            $batchId = (int) MedicineBatch::create([
                'medicine_id'    => $medicineId,
                'batch_number'   => $batchNo ?? self::generateBatchNo(),
                'quantity'       => $qty,
                'purchase_price' => $purchasePrice ?? 0,
                'expiry_date'    => $expiryDate,
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
        }

        self::log($medicineId, $batchId, 'in', $qty, $refType, $refId, $userId, $notes);
        return $batchId;
    }

    /**
     * Remove stock from a specific batch. Throws 422 on insufficient stock.
     */
    public static function decrease(
        int $medicineId,
        int $batchId,
        float $qty,
        string $refType,
        int|string $refId,
        int $userId,
        string $notes = ''
    ): void {
        self::assertPositiveQty($qty);
        $batch = MedicineBatch::find($batchId);
        if (!$batch || (int) $batch['medicine_id'] !== $medicineId) {
            throw new ApiException('Batch not found for this medicine.', 404);
        }
        if ((float) $batch['quantity'] < $qty) {
            throw new ApiException(
                "Insufficient stock in batch {$batch['batch_number']}: available {$batch['quantity']}, requested {$qty}.",
                422
            );
        }
        MedicineBatch::rawExec(
            'UPDATE medicine_batches SET quantity = quantity - :q, updated_at = NOW() WHERE id = :id',
            [':q' => $qty, ':id' => $batchId]
        );
        self::log($medicineId, $batchId, 'out', $qty, $refType, $refId, $userId, $notes);
    }

    /**
     * FIFO sale: consume oldest-expiry batches first across the medicine.
     * Throws 422 when total stock is insufficient. Returns batch allocations.
     *
     * @return array<int, array{batch_id:int, qty:float}>
     */
    public static function decreaseFifo(
        int $medicineId,
        float $qty,
        string $refType,
        int|string $refId,
        int $userId,
        string $notes = ''
    ): array {
        self::assertPositiveQty($qty);
        $batches = MedicineBatch::fifo($medicineId);
        $available = array_sum(array_map(fn($b) => (float) $b['quantity'], $batches));
        if ($available < $qty) {
            $med = Medicine::find($medicineId);
            throw new ApiException(
                sprintf(
                    'Insufficient stock for %s: available %s, requested %s.',
                    $med['medicine_name'] ?? ('#' . $medicineId),
                    rtrim(rtrim(number_format($available, 2), '0'), '.'),
                    rtrim(rtrim(number_format($qty, 2), '0'), '.')
                ),
                422
            );
        }

        $remaining = $qty;
        $allocations = [];
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min((float) $batch['quantity'], $remaining);
            self::decrease($medicineId, (int) $batch['id'], $take, $refType, $refId, $userId, $notes);
            $allocations[] = ['batch_id' => (int) $batch['id'], 'qty' => $take];
            $remaining -= $take;
        }
        return $allocations;
    }

    /** Set a batch to an exact quantity (adjustments / stock verification). */
    public static function setQuantity(
        int $medicineId,
        int $batchId,
        float $newQty,
        int $userId,
        string $notes = '',
        string $refType = self::TYPE_ADJUST,
        int|string $refId = 0
    ): void {
        if ($newQty < 0) {
            throw new ApiException('Quantity cannot be negative.', 422);
        }
        $batch = MedicineBatch::find($batchId);
        if (!$batch || (int) $batch['medicine_id'] !== $medicineId) {
            throw new ApiException('Batch not found for this medicine.', 404);
        }
        $oldQty = (float) $batch['quantity'];
        $diff = $newQty - $oldQty;
        if (abs($diff) < 0.00001) {
            return;
        }
        MedicineBatch::rawExec(
            'UPDATE medicine_batches SET quantity = :q, updated_at = NOW() WHERE id = :id',
            [':q' => $newQty, ':id' => $batchId]
        );
        self::log(
            $medicineId, $batchId, $diff > 0 ? 'in' : 'out', abs($diff),
            $refType, $refId, $userId,
            $notes . " (was {$oldQty}, now {$newQty})"
        );
    }

    /**
     * Move stock between two batches (e.g. warehouse → shelf). Creates the
     * destination batch when $toBatchId is null.
     */
    public static function transfer(
        int $medicineId,
        int $fromBatchId,
        ?int $toBatchId,
        float $qty,
        int $userId,
        ?string $toBatchNo = null,
        string $notes = ''
    ): int {
        self::assertPositiveQty($qty);
        $pdo = Database::pdo();

        $from = MedicineBatch::find($fromBatchId);
        if (!$from || (int) $from['medicine_id'] !== $medicineId) {
            throw new ApiException('Source batch not found.', 404);
        }
        if ((float) $from['quantity'] < $qty) {
            throw new ApiException('Insufficient stock in source batch.', 422);
        }
        if ($toBatchId !== null) {
            $to = MedicineBatch::find($toBatchId);
            if (!$to || (int) $to['medicine_id'] !== $medicineId) {
                throw new ApiException('Destination batch not found.', 404);
            }
        } else {
            $toBatchId = (int) MedicineBatch::create([
                'medicine_id'    => $medicineId,
                'batch_number'   => $toBatchNo ?? self::generateBatchNo(),
                'quantity'       => 0,
                'purchase_price' => $from['purchase_price'] ?? 0,
                'sale_price'     => $from['sale_price'] ?? 0,
                'expiry_date'    => $from['expiry_date'],
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
        }

        $upd = $pdo->prepare('UPDATE medicine_batches SET quantity = quantity + :q, updated_at = NOW() WHERE id = :id');
        $upd->execute([':q' => -$qty, ':id' => $fromBatchId]);
        $upd->execute([':q' => $qty, ':id' => $toBatchId]);

        self::log($medicineId, $fromBatchId, 'out', $qty, self::TYPE_TRANSFER, $toBatchId, $userId, $notes);
        self::log($medicineId, $toBatchId, 'in', $qty, self::TYPE_TRANSFER, $fromBatchId, $userId, $notes);
        return $toBatchId;
    }

    private static function log(
        int $medicineId,
        ?int $batchId,
        string $direction, // in|out
        float $qty,
        string $refType,
        int|string $refId,
        int $userId,
        string $notes
    ): void {
        $after = null;
        if ($batchId) {
            $b = MedicineBatch::find($batchId);
            $after = $b ? (int) $b['quantity'] : null;
        }
        StockTransaction::create([
            'medicine_id'      => $medicineId,
            'batch_id'         => $batchId,
            'transaction_type' => self::transactionType($refType, $direction),
            'quantity_change'  => $direction === 'in' ? $qty : -$qty,
            'quantity_after'   => $after ?? 0,
            'reference_type'   => $refType,
            'reference_id'     => is_numeric($refId) ? (int) $refId : null,
            'notes'            => substr($notes, 0, 500),
            'created_by'       => $userId,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    private static function assertPositiveQty(float $qty): void
    {
        if ($qty <= 0) {
            throw new ApiException('Quantity must be greater than zero.', 422);
        }
    }

    private static function generateBatchNo(): string
    {
        return 'B' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
