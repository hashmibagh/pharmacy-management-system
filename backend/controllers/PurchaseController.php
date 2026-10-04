<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Config\Database;
use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Medicine;
use Pharmacy\Models\Purchase;
use Pharmacy\Models\PurchaseItem;
use Pharmacy\Models\PurchasePayment;
use Pharmacy\Models\Supplier;
use Pharmacy\Services\StockService;

class PurchaseController extends BaseController
{
    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        $search = trim((string) ($q['q'] ?? $q['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(p.invoice_number LIKE :s OR s.name LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        if (!empty($q['supplier_id'])) {
            $where[] = 'p.supplier_id = :sup';
            $params[':sup'] = (int) $q['supplier_id'];
        }
        if (!empty($q['from'])) {
            $where[] = 'p.purchase_date >= :from';
            $params[':from'] = $q['from'];
        }
        if (!empty($q['to'])) {
            $where[] = 'p.purchase_date <= :to';
            $params[':to'] = $q['to'];
        }
        if (!empty($q['payment_status'])) {
            $where[] = 'p.payment_status = :ps';
            $params[':ps'] = $q['payment_status'];
        }
        $w = implode(' AND ', $where);
        $total = Purchase::rawOne("SELECT COUNT(*) AS c FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE {$w}", $params)['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = Purchase::raw(
            "SELECT p.*, p.invoice_number AS invoice_no, s.name AS supplier_name,
                    COALESCE((SELECT SUM(amount) FROM purchase_payments pp WHERE pp.purchase_id = p.id),0) AS paid_amount
             FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id
             WHERE {$w} ORDER BY p.purchase_date DESC, p.id DESC
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
     * Create a purchase: header + items + batch stock-in + optional
     * immediate payment — all in ONE transaction.
     */
    public function store(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'supplier_id'     => 'required|integer',
            'purchase_date'   => 'nullable|date',
            'date'            => 'nullable|date',
                        'invoice_number'  => 'nullable|string|max:50',
            'invoice_no'      => 'nullable|string|max:50',
            'items'           => 'required|array',
            'paid_amount'     => 'nullable|numeric',
            'payment_method'  => 'nullable|string|max:50',
            'discount_amount' => 'nullable|numeric',
            'tax_amount'      => 'nullable|numeric',
            'notes'           => 'nullable|string|max:1000',
        ]);
        if (!Supplier::find((int) $b['supplier_id'])) {
            throw new ApiException('Supplier not found.', 404);
        }
        $purchaseDate = $b['purchase_date'] ?? $b['date'] ?? date('Y-m-d');
        $invoiceNumber = trim((string) ($b['invoice_number'] ?? $b['invoice_no'] ?? ''));
        if ($invoiceNumber === '') {
            $invoiceNumber = $this->generateInvoiceNo();
        } elseif (Purchase::findBy('invoice_number', $invoiceNumber)) {
            throw new ApiException('Invoice number already exists.', 422, ['invoice_number' => ['Invoice number already exists.']]);
        }
        $items = $this->validateItems($b['items']);

        Database::beginTransaction();
        try {
            $totals = $this->computeTotals($items, (float) ($b['discount_amount'] ?? 0), (float) ($b['tax_amount'] ?? 0));
            $paid = (float) ($b['paid_amount'] ?? 0);
            if ($paid < 0 || $paid > $totals['grand'] + 0.001) {
                throw new ApiException('Paid amount is invalid.', 422);
            }
            $purchaseId = (int) Purchase::create([
                'supplier_id'     => (int) $b['supplier_id'],
                'invoice_number'  => $invoiceNumber,
                'purchase_date'   => $purchaseDate,
                'subtotal'        => $totals['subtotal'],
                'discount_amount' => $totals['discount'],
                'tax_amount'      => $totals['tax'],
                'grand_total'     => $totals['grand'],
                'paid_amount'     => $paid,
                'due_amount'      => round($totals['grand'] - $paid, 2),
                'payment_status'  => $paid <= 0 ? 'unpaid' : ($paid + 0.001 >= $totals['grand'] ? 'paid' : 'partial'),
                'notes'           => trim((string) ($b['notes'] ?? '')) ?: null,
                'created_by'      => $this->uid($request),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            foreach ($items as $item) {
                $batchId = StockService::increase(
                    $item['medicine_id'], null, $item['qty'],
                    StockService::TYPE_PURCHASE, $purchaseId, $this->uid($request),
                    $item['purchase_price'], $item['batch_no'] ?? null, $item['expiry_date'] ?? null,
                    'Purchase #' . $purchaseId
                );
                if (!empty($item['mfg_date'])) {
                    \Pharmacy\Models\MedicineBatch::update($batchId, ['manufacturing_date' => $item['mfg_date']]);
                }
                PurchaseItem::create([
                    'purchase_id'    => $purchaseId,
                    'medicine_id'    => $item['medicine_id'],
                    'batch_id'       => $batchId,
                    'batch_number'   => $item['batch_no'] ?? null,
                    'expiry_date'    => $item['expiry_date'] ?? null,
                    'quantity'       => $item['qty'],
                    'purchase_price' => $item['purchase_price'],
                    'discount'       => $item['discount'] ?? 0,
                    'tax'            => $item['tax'] ?? 0,
                    'total'          => $item['line_total'],
                ]);
            }

            if ($paid > 0) {
                $this->applyPayment($purchaseId, $paid, (string) ($b['payment_method'] ?? 'cash'), $request);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'PURCHASE_CREATED', 'purchases', $purchaseId, null, $b);
        Response::success(Purchase::findDetailed($purchaseId), 'Purchase recorded.', 201);
    }

    public function show(array $request): void
    {
        $purchase = Purchase::findDetailed((int) $this->param($request, 'id'));
        if (!$purchase) {
            throw new ApiException('Purchase not found.', 404);
        }
        Response::success($purchase);
    }

    /**
     * Header-only edit; items can only change while nothing is paid yet
     * (otherwise stock/cost history would silently drift).
     */
    public function update(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Purchase::find($id);
        if (!$old) {
            throw new ApiException('Purchase not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'supplier_id'   => 'nullable|integer',
            'purchase_date' => 'nullable|date',
            'notes'         => 'nullable|string|max:1000',
        ]);
        if (!empty($b['supplier_id']) && !Supplier::find((int) $b['supplier_id'])) {
            throw new ApiException('Supplier not found.', 404);
        }
        $data = $this->filtered($b, ['supplier_id', 'purchase_date', 'notes']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        Purchase::update($id, $data);
        $this->audit($request, 'PURCHASE_UPDATED', 'purchases', $id, $old, $data);
        Response::success(Purchase::findDetailed($id), 'Purchase updated.');
    }

    /** Delete only when nothing is paid: reverses the stock that was added. */
    public function destroy(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Purchase::find($id);
        if (!$old) {
            throw new ApiException('Purchase not found.', 404);
        }
        if (Purchase::paidTotal($id) > 0) {
            throw new ApiException('Cannot delete a purchase that has payments. Record a return instead.', 422);
        }
        $items = PurchaseItem::where('purchase_id = :pid', [':pid' => $id]);

        Database::beginTransaction();
        try {
            foreach ($items as $item) {
                StockService::decrease(
                    (int) $item['medicine_id'], (int) $item['batch_id'], (float) $item['quantity'],
                    StockService::TYPE_PURCHASE, $id, $this->uid($request), 'Purchase deleted'
                );
            }
            PurchaseItem::rawExec('DELETE FROM purchase_items WHERE purchase_id = :pid', [':pid' => $id]);
            Purchase::delete($id);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'PURCHASE_DELETED', 'purchases', $id, $old, null);
        Response::success(null, 'Purchase deleted and stock reversed.');
    }

    public function addPayment(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $purchase = Purchase::find($id);
        if (!$purchase) {
            throw new ApiException('Purchase not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'amount'         => 'required|numeric',
            'payment_method' => 'required|string|max:50',
            'payment_date'   => 'nullable|date',
            'reference'      => 'nullable|string|max:100',
            'reference_no'   => 'nullable|string|max:100',
            'notes'          => 'nullable|string|max:500',
            'note'           => 'nullable|string|max:500',
        ]);
        $amount = (float) $b['amount'];
        if ($amount <= 0) {
            throw new ApiException('Amount must be greater than zero.', 422);
        }
        $due = (float) $purchase['grand_total'] - Purchase::paidTotal($id);
        if ($amount > $due + 0.001) {
            throw new ApiException(sprintf('Amount exceeds due balance (%.2f).', $due), 422);
        }

        Database::beginTransaction();
        try {
            $this->applyPayment($id, $amount, (string) $b['payment_method'], $request, $b);
            $this->refreshPaymentStatus($id);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'PAYMENT_CREATED', 'purchase_payments', $id, null, $b);
        Response::success(Purchase::findDetailed($id), 'Payment recorded.', 201);
    }

    /** Return purchased items to the supplier: stock decreases. */
    public function returnPurchase(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $purchase = Purchase::find($id);
        if (!$purchase) {
            throw new ApiException('Purchase not found.', 404);
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
                'purchase_item_id' => 'nullable|integer',
                'item_id'          => 'nullable|integer',
                'qty'              => 'required|numeric',
            ]);
            if ($errs) {
                throw new ApiException('Validation failed', 422, ["items.{$i}" => $errs]);
            }
            $purchaseItemId = (int) ($item['purchase_item_id'] ?? $item['item_id'] ?? 0);
            $pi = PurchaseItem::find($purchaseItemId);
            if (!$pi || (int) $pi['purchase_id'] !== $id) {
                throw new ApiException("Purchase item not found (row {$i}).", 404);
            }
            $already = (float) (Purchase::rawOne(
                'SELECT COALESCE(SUM(quantity),0) AS q FROM purchase_return_items WHERE purchase_item_id = :piid',
                [':piid' => $pi['id']]
            )['q'] ?? 0);
            if ((float) $item['qty'] <= 0 || (float) $item['qty'] > (float) $pi['quantity'] - $already) {
                throw new ApiException("Invalid return qty for item {$pi['id']}.", 422);
            }
            $items[] = ['pi' => $pi, 'qty' => (float) $item['qty']];
        }

        Database::beginTransaction();
        try {
            $total = 0;
            // Insert return header (dedicated table purchase_returns).
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO purchase_returns (return_number, purchase_id, return_date, total_amount, reason, created_by, created_at)
                 VALUES (:rno, :pid, :dt, 0, :reason, :uid, NOW())'
            );
            $stmt->execute([
                ':rno' => $this->generateReturnNo('PR'),
                ':pid' => $id,
                ':dt'  => $b['return_date'] ?? date('Y-m-d'),
                ':reason' => trim((string) ($b['reason'] ?? '')) ?: null,
                ':uid' => $this->uid($request),
            ]);
            $returnId = (int) $pdo->lastInsertId();

            $insItem = $pdo->prepare(
                'INSERT INTO purchase_return_items (return_id, purchase_item_id, quantity, amount)
                 VALUES (:rid, :piid, :qty, :total)'
            );
            foreach ($items as $it) {
                $pi = $it['pi'];
                $lineTotal = round($it['qty'] * (float) $pi['purchase_price'], 2);
                $total += $lineTotal;
                $insItem->execute([
                    ':rid' => $returnId, ':piid' => $pi['id'],
                    ':qty' => $it['qty'], ':total' => $lineTotal,
                ]);
                StockService::decrease(
                    (int) $pi['medicine_id'], (int) $pi['batch_id'], $it['qty'],
                    StockService::TYPE_RETURN, $returnId, $this->uid($request), 'Purchase return'
                );
            }
            $pdo->prepare('UPDATE purchase_returns SET total_amount = :t WHERE id = :id')
                ->execute([':t' => $total, ':id' => $returnId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'PURCHASE_RETURNED', 'purchases', $returnId, null, $b);
        Response::success(['return_id' => $returnId, 'total_amount' => round($total, 2)], 'Purchase return recorded.', 201);
    }

    // ---------------------------------------------------------- internals
    /** @return array<int, array<string, mixed>> normalized items */
    private function validateItems(array $items): array
    {
        if (!$items) {
            throw new ApiException('At least one item is required.', 422);
        }
        $out = [];
        foreach ($items as $i => $item) {
            $errs = Validator::make((array) $item, [
                'medicine_id'      => 'required|integer',
                'qty'              => 'required|numeric',
                'purchase_price'   => 'nullable|numeric',
                'batch_no'         => 'nullable|string|max:50',
                'expiry_date'      => 'nullable|date',
                'mfg_date'         => 'nullable|date',
                'discount'         => 'nullable|numeric',
                'discount_percent' => 'nullable|numeric',
                'tax'              => 'nullable|numeric',
            ]);
            if ($errs) {
                throw new ApiException('Validation failed', 422, ["items.{$i}" => $errs]);
            }
            $medicine = Medicine::find((int) $item['medicine_id']);
            if (!$medicine) {
                throw new ApiException("Medicine not found (row {$i}).", 404);
            }
            $cost = $item['purchase_price'] ?? null;
            if ($cost === null || $cost === '' || (float) $cost < 0) {
                throw new ApiException("Invalid purchase price (row {$i}).", 422);
            }
            $cost = (float) $cost;
            if ((float) $item['qty'] <= 0) {
                throw new ApiException("Invalid qty (row {$i}).", 422);
            }
            $discount = (float) ($item['discount'] ?? 0);
            if (isset($item['discount_percent']) && $item['discount_percent'] !== '') {
                $discount = round((float) $item['qty'] * $cost * (float) $item['discount_percent'] / 100, 2);
            }
            $lineTotal = round((float) $item['qty'] * $cost
                - $discount + (float) ($item['tax'] ?? 0), 2);
            $out[] = [
                'medicine_id'    => (int) $item['medicine_id'],
                'qty'            => (float) $item['qty'],
                'purchase_price' => $cost,
                'batch_no'       => isset($item['batch_no']) ? trim((string) $item['batch_no']) : null,
                'expiry_date'    => $item['expiry_date'] ?? null,
                'mfg_date'       => $item['mfg_date'] ?? null,
                'discount'       => $discount,
                'tax'            => (float) ($item['tax'] ?? 0),
                'line_total'     => $lineTotal,
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
        return 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function generateReturnNo(string $prefix): string
    {
        return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function applyPayment(int $purchaseId, float $amount, string $method, array $request, array $extra = []): void
    {
        PurchasePayment::create([
            'purchase_id'    => $purchaseId,
            'amount'         => $amount,
            'payment_method' => $method,
            'payment_date'   => $extra['payment_date'] ?? date('Y-m-d'),
            'reference'      => $extra['reference'] ?? $extra['reference_no'] ?? null,
            'notes'          => isset($extra['notes']) ? trim((string) $extra['notes'])
                : (isset($extra['note']) ? trim((string) $extra['note']) : null),
            'created_by'     => $this->uid($request),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    private function refreshPaymentStatus(int $purchaseId): void
    {
        $p = Purchase::find($purchaseId);
        $paid = Purchase::paidTotal($purchaseId);
        $grand = (float) $p['grand_total'];
        $status = $paid <= 0 ? 'unpaid' : ($paid + 0.001 >= $grand ? 'paid' : 'partial');
        Purchase::update($purchaseId, [
            'payment_status' => $status,
            'paid_amount'    => $paid,
            'due_amount'     => round($grand - $paid, 2),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}
