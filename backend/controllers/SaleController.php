<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Config\Database;
use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Customer;
use Pharmacy\Models\Medicine;
use Pharmacy\Models\MedicineBatch;
use Pharmacy\Models\Sale;
use Pharmacy\Models\SaleItem;
use Pharmacy\Models\SalePayment;
use Pharmacy\Services\NotificationService;
use Pharmacy\Services\StockService;

class SaleController extends BaseController
{
    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = ['s.deleted_at IS NULL'];
        $params = [];
        $search = trim((string) ($q['q'] ?? $q['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(s.invoice_number LIKE :s OR c.name LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        if (!empty($q['customer_id'])) {
            $where[] = 's.customer_id = :cid';
            $params[':cid'] = (int) $q['customer_id'];
        }
        if (!empty($q['from'])) {
            $where[] = 's.sale_date >= :from';
            $params[':from'] = $q['from'];
        }
        if (!empty($q['to'])) {
            $where[] = 's.sale_date <= :to';
            $params[':to'] = $q['to'];
        }
        if (!empty($q['payment_status'])) {
            $where[] = 's.payment_status = :ps';
            $params[':ps'] = $q['payment_status'];
        }
        $w = implode(' AND ', $where);
        $total = Sale::rawOne("SELECT COUNT(*) AS c FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE {$w}", $params)['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = Sale::raw(
            "SELECT s.*, s.invoice_number AS invoice_no, c.name AS customer_name,
                    COALESCE((SELECT SUM(amount) FROM sale_payments sp WHERE sp.sale_id = s.id),0) AS paid_amount
             FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
             WHERE {$w} ORDER BY s.sale_date DESC, s.id DESC
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
     * POS sale — ONE transaction:
     *   validate stock → sale + items → FIFO batch decrease →
     *   payment → customer ledger (derived) → reward points →
     *   stock_transactions → audit log.
     */
    public function store(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'customer_id'     => 'nullable|integer',
            'sale_date'       => 'nullable|date',
            'items'           => 'required|array',
            'paid_amount'     => 'nullable|numeric',
            'payment_method'  => 'nullable|string|max:50',
            'discount_amount' => 'nullable|numeric',
            'discount'        => 'nullable|numeric',
            'tax_amount'      => 'nullable|numeric',
            'notes'           => 'nullable|string|max:1000',
        ]);

        $customerId = isset($b['customer_id']) ? (int) $b['customer_id'] : null;
        if ($customerId && !Customer::find($customerId)) {
            throw new ApiException('Customer not found.', 404);
        }
        $items = $this->validateItems($b['items']);

        Database::beginTransaction();
        try {
            $discount = (float) ($b['discount_amount'] ?? $b['discount'] ?? 0);
            $totals = $this->computeTotals($items, $discount, (float) ($b['tax_amount'] ?? 0));
            $paid = (float) ($b['paid_amount'] ?? 0);
            if ($paid < 0 || $paid > $totals['grand'] + 0.001) {
                throw new ApiException('Paid amount is invalid.', 422);
            }
            $saleId = (int) Sale::create([
                'customer_id'     => $customerId,
                'invoice_number'  => $this->generateInvoiceNo(),
                'sale_date'       => $b['sale_date'] ?? date('Y-m-d H:i:s'),
                'subtotal'        => $totals['subtotal'],
                'discount_amount' => $totals['discount'],
                'tax_amount'      => $totals['tax'],
                'grand_total'     => $totals['grand'],
                'paid_amount'     => $paid,
                'due_amount'      => round($totals['grand'] - $paid, 2),
                'payment_status'  => $paid <= 0 ? 'unpaid' : ($paid + 0.001 >= $totals['grand'] ? 'paid' : 'partial'),
                'payment_method'  => (string) ($b['payment_method'] ?? 'cash'),
                'notes'           => trim((string) ($b['notes'] ?? '')) ?: null,
                'created_by'      => $this->uid($request),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            foreach ($items as $item) {
                if (!empty($item['batch_id'])) {
                    // Caller picked a specific batch (POS batch picker).
                    StockService::decrease(
                        $item['medicine_id'], $item['batch_id'], $item['qty'],
                        StockService::TYPE_SALE, $saleId, $this->uid($request),
                        'Sale #' . $saleId
                    );
                    $batchId = $item['batch_id'];
                } else {
                    // decreaseFifo throws 422 on insufficient stock → whole sale rolls back.
                    $allocations = StockService::decreaseFifo(
                        $item['medicine_id'], $item['qty'],
                        StockService::TYPE_SALE, $saleId, $this->uid($request),
                        'Sale #' . $saleId
                    );
                    $batchId = $allocations[0]['batch_id'] ?? null;
                }
                SaleItem::create([
                    'sale_id'     => $saleId,
                    'medicine_id' => $item['medicine_id'],
                    'batch_id'    => $batchId,
                    'quantity'    => $item['qty'],
                    'sale_price'  => $item['unit_price'],
                    'discount'    => $item['discount'],
                    'tax'         => $item['tax'],
                    'total'       => $item['line_total'],
                ]);
            }

            if ($paid > 0) {
                SalePayment::create([
                    'sale_id'        => $saleId,
                    'amount'         => $paid,
                    'payment_method' => (string) ($b['payment_method'] ?? 'cash'),
                    'payment_date'   => date('Y-m-d'),
                    'created_by'     => $this->uid($request),
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
            }

            // Reward points: 1 point per 100 of grand total (configurable via settings later).
            if ($customerId && $totals['grand'] > 0) {
                $points = (int) floor($totals['grand'] / 100);
                if ($points > 0) {
                    Customer::rawExec(
                        'UPDATE customers SET reward_points = COALESCE(reward_points,0) + :p WHERE id = :id',
                        [':p' => $points, ':id' => $customerId]
                    );
                }
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'SALE_CREATED', 'sales', $saleId, null, [
            'invoice' => true, 'grand_total' => $totals['grand'], 'items' => count($items),
        ]);
        NotificationService::checkLowStock();
        Response::success(Sale::findDetailed($saleId), 'Sale completed.', 201);
    }

    public function show(array $request): void
    {
        $sale = Sale::findDetailed((int) $this->param($request, 'id'));
        if (!$sale) {
            throw new ApiException('Sale not found.', 404);
        }
        Response::success($sale);
    }

    /**
     * Return sold items: validates against sold qty, restocks via
     * StockService, records the return + refund in one transaction.
     */
    public function returnSale(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $sale = Sale::find($id);
        if (!$sale) {
            throw new ApiException('Sale not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'items'       => 'required|array',
            'return_date' => 'nullable|date',
            'reason'      => 'nullable|string|max:500',
        ]);

        $items = [];
        foreach ($b['items'] as $i => $item) {
            $errs = Validator::make((array) $item, [
                'sale_item_id' => 'nullable|integer',
                'item_id'      => 'nullable|integer',
                'qty'          => 'required|numeric',
            ]);
            if ($errs) {
                throw new ApiException('Validation failed', 422, ["items.{$i}" => $errs]);
            }
            $saleItemId = (int) ($item['sale_item_id'] ?? $item['item_id'] ?? 0);
            $si = SaleItem::find($saleItemId);
            if (!$si || (int) $si['sale_id'] !== $id) {
                throw new ApiException("Sale item not found (row {$i}).", 404);
            }
            $already = (float) (Sale::rawOne(
                'SELECT COALESCE(SUM(quantity),0) AS q FROM sales_return_items WHERE sale_item_id = :siid',
                [':siid' => $si['id']]
            )['q'] ?? 0);
            if ((float) $item['qty'] <= 0 || (float) $item['qty'] > (float) $si['quantity'] - $already) {
                throw new ApiException("Invalid return qty for item {$si['id']}.", 422);
            }
            $items[] = ['si' => $si, 'qty' => (float) $item['qty']];
        }

        Database::beginTransaction();
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO sale_returns (return_number, sale_id, return_date, total_amount, reason, created_by, created_at)
                 VALUES (:rno, :sid, :dt, 0, :reason, :uid, NOW())'
            );
            $stmt->execute([
                ':rno' => $this->generateReturnNo('SR'),
                ':sid' => $id,
                ':dt'  => $b['return_date'] ?? date('Y-m-d'),
                ':reason' => trim((string) ($b['reason'] ?? '')) ?: null,
                ':uid' => $this->uid($request),
            ]);
            $returnId = (int) $pdo->lastInsertId();

            $insItem = $pdo->prepare(
                'INSERT INTO sales_return_items (return_id, sale_item_id, quantity, amount)
                 VALUES (:rid, :siid, :qty, :total)'
            );
            $total = 0;
            foreach ($items as $it) {
                $si = $it['si'];
                $lineTotal = round($it['qty'] * (float) $si['sale_price'], 2);
                $total += $lineTotal;
                $insItem->execute([
                    ':rid' => $returnId, ':siid' => $si['id'],
                    ':qty' => $it['qty'], ':total' => $lineTotal,
                ]);
                // Restock to the original batch when possible, else a generic batch.
                StockService::increase(
                    (int) $si['medicine_id'], $si['batch_id'] ? (int) $si['batch_id'] : null,
                    $it['qty'], StockService::TYPE_RETURN, $returnId, $this->uid($request),
                    null, null, null, 'Sale return'
                );
            }
            $pdo->prepare('UPDATE sale_returns SET total_amount = :t WHERE id = :id')
                ->execute([':t' => $total, ':id' => $returnId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'SALE_RETURNED', 'sales', $returnId, null, $b);
        Response::success(['return_id' => $returnId, 'total_amount' => round($total, 2)], 'Sale return recorded.', 201);
    }

    // ---------------------------------------------------------- internals
    /** @return array<int, array<string, mixed>> */
    private function validateItems(array $items): array
    {
        if (!$items) {
            throw new ApiException('At least one item is required.', 422);
        }
        $out = [];
        foreach ($items as $i => $item) {
            $errs = Validator::make((array) $item, [
                'medicine_id'      => 'required|integer',
                'batch_id'         => 'nullable|integer',
                'qty'              => 'required|numeric',
                'unit_price'       => 'nullable|numeric',
                'discount'         => 'nullable|numeric',
                'discount_percent' => 'nullable|numeric',
                'tax'              => 'nullable|numeric',
            ]);
            if ($errs) {
                throw new ApiException('Validation failed', 422, ["items.{$i}" => $errs]);
            }
            $medicineId = (int) $item['medicine_id'];
            $medicine = Medicine::find($medicineId);
            if (!$medicine) {
                throw new ApiException("Medicine not found (row {$i}).", 404);
            }
            if ((float) $item['qty'] <= 0) {
                throw new ApiException("Invalid qty (row {$i}).", 422);
            }
            $batchId = isset($item['batch_id']) ? (int) $item['batch_id'] : null;
            if ($batchId) {
                $batch = MedicineBatch::find($batchId);
                if (!$batch || (int) $batch['medicine_id'] !== $medicineId) {
                    throw new ApiException("Batch not found for this medicine (row {$i}).", 404);
                }
            }
            // Default unit price = latest batch sale_price (pricing lives on batches).
            $latest = MedicineBatch::rawOne(
                'SELECT sale_price FROM medicine_batches
                 WHERE medicine_id = :mid AND deleted_at IS NULL ORDER BY id DESC LIMIT 1',
                [':mid' => $medicineId]
            );
            $unitPrice = isset($item['unit_price']) && $item['unit_price'] !== ''
                ? (float) $item['unit_price']
                : (float) ($latest['sale_price'] ?? 0);
            $discount = (float) ($item['discount'] ?? 0);
            if (isset($item['discount_percent']) && $item['discount_percent'] !== '') {
                $discount = round((float) $item['qty'] * $unitPrice * (float) $item['discount_percent'] / 100, 2);
            }
            $tax = (float) ($item['tax'] ?? 0);
            $lineTotal = round((float) $item['qty'] * $unitPrice - $discount + $tax, 2);
            if ($lineTotal < 0) {
                throw new ApiException("Line total cannot be negative (row {$i}).", 422);
            }
            $out[] = [
                'medicine_id' => $medicineId,
                'batch_id'    => $batchId,
                'qty'         => (float) $item['qty'],
                'unit_price'  => $unitPrice,
                'discount'    => $discount,
                'tax'         => $tax,
                'line_total'  => $lineTotal,
            ];
        }
        return $out;
    }

    private function computeTotals(array $items, float $discount, float $tax): array
    {
        $subtotal = round(array_sum(array_column($items, 'line_total')), 2);
        $grand = round($subtotal - $discount + $tax, 2);
        if ($grand < 0) {
            throw new ApiException('Grand total cannot be negative.', 422);
        }
        return ['subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'grand' => $grand];
    }

    private function generateInvoiceNo(): string
    {
        return 'INV-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function generateReturnNo(string $prefix): string
    {
        return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
