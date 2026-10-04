<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Config\Database;
use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Medicine;
use Pharmacy\Models\MedicineBatch;
use Pharmacy\Models\StockTransaction;
use Pharmacy\Services\NotificationService;
use Pharmacy\Services\StockService;

/**
 * All stock mutations go through StockService so stock_transactions
 * rows are always written. Stock lives ONLY in medicine_batches.quantity.
 */
class InventoryController extends BaseController
{
    /** Stock overview: medicines with quantities, values and batch counts. */
    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = ['m.deleted_at IS NULL'];
        $params = [];
        $search = trim((string) ($q['q'] ?? $q['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(m.medicine_name LIKE :s OR m.barcode LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        if (!empty($q['category_id'])) {
            $where[] = 'm.category_id = :cat';
            $params[':cat'] = (int) $q['category_id'];
        }
        if (!empty($q['low_stock'])) {
            $where[] = Medicine::STOCK_SQL . ' > 0 AND ' . Medicine::STOCK_SQL . ' <= ' . Medicine::MIN_STOCK_SQL;
        }
        if (!empty($q['out_of_stock'])) {
            $where[] = Medicine::STOCK_SQL . ' <= 0';
        }
        $w = implode(' AND ', $where);
        $total = Medicine::rawOne("SELECT COUNT(*) AS c FROM medicines m WHERE {$w}", $params)['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = Medicine::raw(
            "SELECT m.id, m.medicine_name AS name, m.medicine_name AS medicine_name, m.barcode,
                    " . Medicine::STOCK_SQL . " AS stock,
                    " . Medicine::STOCK_SQL . " AS qty,
                    " . Medicine::MIN_STOCK_SQL . " AS min_stock,
                    " . Medicine::SALE_PRICE_SQL . " AS sale_price,
                    " . Medicine::PURCHASE_PRICE_SQL . " AS purchase_price,
                    (SELECT COALESCE(SUM(b.quantity * b.purchase_price),0) FROM medicine_batches b
                      WHERE b.medicine_id = m.id AND b.deleted_at IS NULL) AS stock_value,
                    c.name AS category_name,
                    (SELECT COUNT(*) FROM medicine_batches b WHERE b.medicine_id = m.id
                      AND b.quantity > 0 AND b.deleted_at IS NULL) AS batch_count
             FROM medicines m LEFT JOIN medicine_categories c ON c.id = m.category_id
             WHERE {$w} ORDER BY m.medicine_name ASC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        Response::success([
            'data' => $data,
            'meta' => [
                'current_page' => $page, 'per_page' => $perPage,
                'total' => (int) $total, 'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    /**
     * Adjust stock. Accepts either an absolute new_qty (setQuantity) or a
     * relative adjustment {type: in|out, qty} as sent by the frontend.
     */
    public function adjust(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'medicine_id' => 'required|integer',
            'batch_id'    => 'required|integer',
            'new_qty'     => 'nullable|numeric',
            'type'        => 'nullable|in:in,out',
            'qty'         => 'nullable|numeric',
            'reason'      => 'nullable|string|max:500',
        ]);

        $medicineId = (int) $b['medicine_id'];
        $batchId = (int) $b['batch_id'];
        $reason = trim((string) ($b['reason'] ?? 'Manual adjustment'));

        Database::beginTransaction();
        try {
            if (isset($b['new_qty']) && $b['new_qty'] !== '' && $b['new_qty'] !== null) {
                StockService::setQuantity(
                    $medicineId, $batchId, (float) $b['new_qty'],
                    $this->uid($request), $reason
                );
            } else {
                $qty = (float) ($b['qty'] ?? 0);
                if ($qty <= 0) {
                    throw new ApiException('Provide new_qty or a positive qty with type in|out.', 422);
                }
                if (($b['type'] ?? 'in') === 'out') {
                    StockService::decrease(
                        $medicineId, $batchId, $qty,
                        StockService::TYPE_ADJUST, 0, $this->uid($request), $reason
                    );
                } else {
                    StockService::increase(
                        $medicineId, $batchId, $qty,
                        StockService::TYPE_ADJUST, 0, $this->uid($request),
                        null, null, null, $reason
                    );
                }
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'STOCK_ADJUSTED', 'inventory', $batchId, null, $b);
        NotificationService::checkLowStock();
        Response::success(null, 'Stock adjusted.');
    }

    public function stockIn(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'medicine_id'    => 'required|integer',
            'batch_id'       => 'nullable|integer',
            'qty'            => 'required|numeric',
            'batch_no'       => 'nullable|string|max:50',
            'expiry_date'    => 'nullable|date',
            'purchase_price' => 'nullable|numeric',
            'notes'          => 'nullable|string|max:500',
        ]);

        Database::beginTransaction();
        try {
            $batchId = StockService::increase(
                (int) $b['medicine_id'],
                isset($b['batch_id']) ? (int) $b['batch_id'] : null,
                (float) $b['qty'],
                StockService::TYPE_ADJUST, 0, $this->uid($request),
                isset($b['purchase_price']) ? (float) $b['purchase_price'] : null,
                $b['batch_no'] ?? null,
                $b['expiry_date'] ?? null,
                trim((string) ($b['notes'] ?? 'Manual stock-in'))
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'STOCK_ADJUSTED', 'inventory', $batchId, null, $b);
        Response::success(['batch_id' => $batchId], 'Stock added.');
    }

    public function stockOut(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'medicine_id' => 'required|integer',
            'batch_id'    => 'required|integer',
            'qty'         => 'required|numeric',
            'reason'      => 'required|string|max:500',
            'is_damaged'  => 'nullable|boolean',
        ]);

        Database::beginTransaction();
        try {
            StockService::decrease(
                (int) $b['medicine_id'], (int) $b['batch_id'], (float) $b['qty'],
                !empty($b['is_damaged']) ? StockService::TYPE_DAMAGED : StockService::TYPE_ADJUST,
                0, $this->uid($request), trim((string) $b['reason'])
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'STOCK_ADJUSTED', 'inventory', $b['batch_id'], null, $b);
        NotificationService::checkLowStock();
        Response::success(null, 'Stock removed.');
    }

    /** Record opening stock for a medicine (initial load). */
    public function openingStock(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'medicine_id'    => 'required|integer',
            'qty'            => 'required|numeric',
            'batch_no'       => 'nullable|string|max:50',
            'expiry_date'    => 'nullable|date',
            'purchase_price' => 'nullable|numeric',
        ]);

        Database::beginTransaction();
        try {
            $batchId = StockService::increase(
                (int) $b['medicine_id'], null, (float) $b['qty'],
                StockService::TYPE_OPENING, 0, $this->uid($request),
                isset($b['purchase_price']) ? (float) $b['purchase_price'] : null,
                $b['batch_no'] ?? null, $b['expiry_date'] ?? null, 'Opening stock'
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'STOCK_ADJUSTED', 'inventory', $batchId, null, $b);
        Response::success(['batch_id' => $batchId], 'Opening stock recorded.');
    }

    /** Move stock between two batches of the same medicine. */
    public function transfer(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'medicine_id'   => 'required|integer',
            'from_batch_id' => 'required|integer',
            'to_batch_id'   => 'nullable|integer',
            'to_batch_no'   => 'nullable|string|max:50',
            'qty'           => 'required|numeric',
            'notes'         => 'nullable|string|max:500',
        ]);

        Database::beginTransaction();
        try {
            $toBatchId = StockService::transfer(
                (int) $b['medicine_id'], (int) $b['from_batch_id'],
                isset($b['to_batch_id']) ? (int) $b['to_batch_id'] : null,
                (float) $b['qty'], $this->uid($request),
                $b['to_batch_no'] ?? null, trim((string) ($b['notes'] ?? ''))
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'STOCK_TRANSFERRED', 'inventory', $b['from_batch_id'], null, $b);
        Response::success(['to_batch_id' => $toBatchId], 'Stock transferred.');
    }

    /** Physical count verification: set each batch to its counted qty. */
    public function verification(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'items'               => 'required|array',
            'items.*.medicine_id' => 'required|integer',
            'items.*.batch_id'    => 'required|integer',
            'items.*.counted_qty' => 'required|numeric',
        ]);
        // Our simple validator doesn't expand wildcards; validate manually.
        foreach ($b['items'] as $i => $item) {
            $errs = Validator::make((array) $item, [
                'medicine_id' => 'required|integer',
                'batch_id'    => 'required|integer',
                'counted_qty' => 'required|numeric',
            ]);
            if ($errs) {
                throw new ApiException('Validation failed', 422, ["items.{$i}" => $errs]);
            }
        }

        Database::beginTransaction();
        try {
            foreach ($b['items'] as $item) {
                StockService::setQuantity(
                    (int) $item['medicine_id'], (int) $item['batch_id'], (float) $item['counted_qty'],
                    $this->uid($request), 'Physical stock verification',
                    StockService::TYPE_VERIFIED, 0
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'STOCK_VERIFIED', 'inventory', null, null, ['items' => count($b['items'])]);
        NotificationService::checkLowStock();
        Response::success(null, 'Stock verification saved.');
    }

    /** Stock valuation: FIFO (batch costs) or average cost. */
    public function valuation(array $request): void
    {
        $q = $this->query($request);
        $method = strtolower((string) ($q['method'] ?? 'fifo'));
        if (!in_array($method, ['fifo', 'average'], true)) {
            throw new ApiException('Method must be fifo or average.', 422);
        }

        if ($method === 'fifo') {
            $rows = Medicine::raw(
                'SELECT m.id, m.medicine_name AS name, m.barcode,
                        ' . Medicine::STOCK_SQL . ' AS stock,
                        COALESCE(SUM(CASE WHEN b.quantity > 0 THEN b.quantity * b.purchase_price ELSE 0 END), 0) AS stock_value
                 FROM medicines m
                 LEFT JOIN medicine_batches b ON b.medicine_id = m.id AND b.deleted_at IS NULL
                 WHERE m.deleted_at IS NULL
                 GROUP BY m.id ORDER BY m.medicine_name ASC'
            );
        } else {
            $rows = Medicine::raw(
                'SELECT m.id, m.medicine_name AS name, m.barcode,
                        ' . Medicine::STOCK_SQL . ' AS stock,
                        COALESCE(AVG(CASE WHEN b.quantity > 0 THEN b.purchase_price END), 0) AS avg_cost,
                        ' . Medicine::STOCK_SQL . ' * COALESCE(AVG(CASE WHEN b.quantity > 0 THEN b.purchase_price END), 0) AS stock_value
                 FROM medicines m
                 LEFT JOIN medicine_batches b ON b.medicine_id = m.id AND b.deleted_at IS NULL
                 WHERE m.deleted_at IS NULL
                 GROUP BY m.id ORDER BY m.medicine_name ASC'
            );
        }
        $total = array_sum(array_map(fn($r) => (float) $r['stock_value'], $rows));
        Response::success(['method' => $method, 'total_value' => round($total, 2), 'items' => $rows]);
    }

    public function lowStock(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = 'm.status = \'active\' AND m.deleted_at IS NULL';
        $total = Medicine::rawOne(
            "SELECT COUNT(*) AS c FROM (
               SELECT m.id FROM medicines m
               LEFT JOIN medicine_batches b ON b.medicine_id = m.id AND b.deleted_at IS NULL
               WHERE {$where}
               GROUP BY m.id
               HAVING COALESCE(SUM(b.quantity),0) <= COALESCE(MIN(b.minimum_stock),0)
             ) t"
        )['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = Medicine::raw(
            "SELECT m.id, m.medicine_name AS name, m.medicine_name AS medicine_name, m.barcode,
                    COALESCE(SUM(b.quantity),0) AS stock,
                    COALESCE(SUM(b.quantity),0) AS qty,
                    COALESCE(MIN(b.minimum_stock),0) AS min_stock,
                    (COALESCE(MIN(b.minimum_stock),0) - COALESCE(SUM(b.quantity),0)) AS shortage
             FROM medicines m
             LEFT JOIN medicine_batches b ON b.medicine_id = m.id AND b.deleted_at IS NULL
             WHERE {$where}
             GROUP BY m.id
             HAVING stock <= min_stock
             ORDER BY shortage DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        Response::success([
            'data' => $data,
            'meta' => [
                'current_page' => $page, 'per_page' => $perPage,
                'total' => (int) $total, 'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    public function expiring(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $days = max(1, (int) ($q['days'] ?? 90));
        $params = [':days' => $days];
        $where = 'b.quantity > 0 AND b.expiry_date IS NOT NULL AND b.deleted_at IS NULL
                  AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)';
        $total = MedicineBatch::rawOne(
            "SELECT COUNT(*) AS c FROM medicine_batches b WHERE {$where}",
            $params
        )['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = MedicineBatch::raw(
            'SELECT b.*, b.quantity AS qty, b.batch_number AS batch_no,
                    b.minimum_stock AS min_stock, m.medicine_name,
                    DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry
             FROM medicine_batches b JOIN medicines m ON m.id = b.medicine_id
             WHERE ' . $where . '
             ORDER BY b.expiry_date ASC
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        Response::success([
            'data' => $data,
            'meta' => [
                'current_page' => $page, 'per_page' => $perPage,
                'total' => (int) $total, 'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    public function history(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        Response::success(StockTransaction::history($page, $perPage, [
            'medicine_id' => $q['medicine_id'] ?? null,
            'type'        => $q['type'] ?? null,
            'from'        => $q['from'] ?? null,
            'to'          => $q['to'] ?? null,
        ]));
    }
}
