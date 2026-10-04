<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Excel;
use Pharmacy\Helpers\Pdf;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Customer;
use Pharmacy\Models\Expense;
use Pharmacy\Models\Medicine;
use Pharmacy\Models\Purchase;
use Pharmacy\Models\Sale;
use Pharmacy\Models\Setting;
use Pharmacy\Models\Supplier;
use Pharmacy\Services\ReportService;

/**
 * PDF (dompdf) and Excel (PhpSpreadsheet) exports.
 * Both libraries are optional — endpoints return a clear 501 JSON error
 * with install instructions when the library is missing.
 */
class ExportController extends BaseController
{
    // ------------------------------------------------------------- PDF
    /**
     * GET /api/export/pdf?type=invoice&id=123
     *   type: invoice | receipt | report | statement
     *   report: &entity=sales|purchases|inventory|expiry|expenses&from&to
     *   statement: &id=<customer_id>&from&to
     */
    public function pdf(array $request): void
    {
        Pdf::requireAvailable();
        $q = $this->query($request);
        $type = strtolower(trim((string) ($q['type'] ?? '')));

        match ($type) {
            'invoice'   => $this->saleInvoicePdf((int) ($q['id'] ?? 0)),
            'receipt'   => $this->paymentReceiptPdf((int) ($q['id'] ?? 0)),
            'report'    => $this->reportPdf($q),
            'statement' => $this->customerStatementPdf((int) ($q['id'] ?? 0), $q),
            default     => throw new ApiException('Unknown PDF type. Use invoice, receipt, report or statement.', 422),
        };
    }

    private function shopHeader(): string
    {
        $s = Setting::allKeyed();
        $name = Pdf::esc($s['shop_name'] ?? 'Pharmacy');
        $addr = Pdf::esc($s['shop_address'] ?? '');
        $phone = Pdf::esc($s['shop_phone'] ?? '');
        return "<div class=\"header\"><div><h1>{$name}</h1>"
            . "<div class=\"meta\">{$addr}<br>{$phone}</div></div>"
            . '<div class="meta" style="text-align:right">' . date('d M Y, h:i A') . '</div></div>';
    }

    private function saleInvoicePdf(int $saleId): void
    {
        $sale = Sale::findDetailed($saleId);
        if (!$sale) {
            throw new ApiException('Sale not found.', 404);
        }
        $rows = '';
        foreach ($sale['items'] as $i => $it) {
            $rows .= '<tr><td>' . ($i + 1) . '</td><td>' . Pdf::esc($it['medicine_name']) . '</td>'
                . "<td>{$it['qty']}</td><td>" . number_format((float) $it['unit_price'], 2) . '</td>'
                . '<td>' . number_format((float) $it['discount'], 2) . '</td>'
                . '<td>' . number_format((float) $it['total'], 2) . '</td></tr>';
        }
        $payRows = '';
        foreach ($sale['payments'] as $p) {
            $payRows .= '<tr><td>' . Pdf::esc($p['payment_date']) . '</td><td>' . Pdf::esc($p['payment_method']) . '</td>'
                . '<td>' . number_format((float) $p['amount'], 2) . '</td></tr>';
        }
        $html = '<html><head>' . Pdf::baseStyles() . '</head><body><div class="doc">'
            . $this->shopHeader()
            . '<h2>Sale Invoice <span class="badge">' . Pdf::esc($sale['invoice_number']) . '</span></h2>'
            . '<div class="meta">Customer: ' . Pdf::esc($sale['customer_name'] ?? 'Walk-in')
            . ' &nbsp;|&nbsp; Date: ' . Pdf::esc($sale['sale_date']) . '</div>'
            . '<table><tr><th>#</th><th>Medicine</th><th>Qty</th><th>Price</th><th>Discount</th><th>Total</th></tr>'
            . $rows . '</table>'
            . '<table class="totals">'
            . '<tr><td>Subtotal</td><td>' . number_format((float) $sale['subtotal'], 2) . '</td></tr>'
            . '<tr><td>Discount</td><td>' . number_format((float) $sale['discount_amount'], 2) . '</td></tr>'
            . '<tr><td>Tax</td><td>' . number_format((float) $sale['tax_amount'], 2) . '</td></tr>'
            . '<tr class="grand"><td>Grand Total</td><td>' . number_format((float) $sale['grand_total'], 2) . '</td></tr>'
            . '</table>'
            . ($payRows ? '<h3>Payments</h3><table><tr><th>Date</th><th>Method</th><th>Amount</th></tr>' . $payRows . '</table>' : '')
            . '<div class="footer">Thank you for your business.</div>'
            . '</div></body></html>';
        Pdf::download($html, 'invoice_' . $sale['invoice_number'] . '.pdf');
    }

    private function paymentReceiptPdf(int $paymentId): void
    {
        $p = Sale::rawOne(
            'SELECT sp.*, s.invoice_number FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id WHERE sp.id = :id LIMIT 1',
            [':id' => $paymentId]
        );
        if (!$p) {
            throw new ApiException('Payment not found.', 404);
        }
        $html = '<html><head>' . Pdf::baseStyles() . '</head><body><div class="doc">'
            . $this->shopHeader()
            . '<h2>Payment Receipt</h2>'
            . '<table>'
            . '<tr><td><b>Receipt #</b></td><td>' . Pdf::esc($p['id']) . '</td></tr>'
            . '<tr><td><b>Invoice</b></td><td>' . Pdf::esc($p['invoice_number']) . '</td></tr>'
            . '<tr><td><b>Date</b></td><td>' . Pdf::esc($p['payment_date']) . '</td></tr>'
            . '<tr><td><b>Method</b></td><td>' . Pdf::esc($p['payment_method']) . '</td></tr>'
            . '<tr class="grand"><td><b>Amount</b></td><td><b>' . number_format((float) $p['amount'], 2) . '</b></td></tr>'
            . '</table><div class="footer">System generated receipt.</div></div></body></html>';
        Pdf::download($html, 'receipt_' . $p['id'] . '.pdf');
    }

    private function reportPdf(array $q): void
    {
        $entity = strtolower(trim((string) ($q['entity'] ?? 'sales')));
        $report = ReportService::custom($entity, $q);
        $rows = '';
        $i = 0;
        foreach (($report['data'] ?? []) as $r) {
            $i++;
            // Generic two-column-ish rendering: show first 6 scalar fields.
            $cells = '';
            $n = 0;
            foreach ($r as $k => $v) {
                if ($n++ >= 6 || is_array($v)) {
                    continue;
                }
                $cells .= '<td>' . Pdf::esc(is_scalar($v) ? $v : '') . '</td>';
            }
            $rows .= "<tr><td>{$i}</td>{$cells}</tr>";
        }
        $summary = '';
        foreach (($report['summary'] ?? []) as $k => $v) {
            if (is_scalar($v)) {
                $summary .= '<tr><td>' . Pdf::esc(ucwords(str_replace('_', ' ', (string) $k))) . '</td><td>' . Pdf::esc($v) . '</td></tr>';
            }
        }
        $title = ucfirst($entity) . ' Report';
        $range = 'Period: ' . Pdf::esc(($q['from'] ?? '—') . ' to ' . ($q['to'] ?? '—'));
        $html = '<html><head>' . Pdf::baseStyles() . '</head><body><div class="doc">'
            . $this->shopHeader()
            . "<h2>{$title}</h2><div class=\"meta\">{$range}</div>"
            . '<table><tr><th>#</th><th colspan="6">Record</th></tr>' . $rows . '</table>'
            . ($summary ? '<h3>Summary</h3><table>' . $summary . '</table>' : '')
            . '<div class="footer">Generated ' . date('d M Y, h:i A') . '</div>'
            . '</div></body></html>';
        Pdf::download($html, $entity . '_report_' . date('Ymd_His') . '.pdf', 'A4', 'landscape');
    }

    private function customerStatementPdf(int $customerId, array $q): void
    {
        $customer = Customer::find($customerId);
        if (!$customer) {
            throw new ApiException('Customer not found.', 404);
        }
        $ledger = Customer::ledger($customerId, $q['from'] ?? null, $q['to'] ?? null);
        $rows = '';
        foreach ($ledger['entries'] as $e) {
            $rows .= '<tr><td>' . Pdf::esc($e['txn_date']) . '</td><td>' . Pdf::esc($e['description']) . '</td>'
                . '<td>' . number_format((float) $e['debit'], 2) . '</td>'
                . '<td>' . number_format((float) $e['credit'], 2) . '</td>'
                . '<td>' . number_format((float) $e['balance'], 2) . '</td></tr>';
        }
        $html = '<html><head>' . Pdf::baseStyles() . '</head><body><div class="doc">'
            . $this->shopHeader()
            . '<h2>Customer Statement</h2>'
            . '<div class="meta">' . Pdf::esc($customer['name']) . ' — ' . Pdf::esc($customer['phone'] ?? '') . '</div>'
            . '<table><tr><th>Date</th><th>Description</th><th>Debit</th><th>Credit</th><th>Balance</th></tr>'
            . $rows
            . '<tr class="grand"><td colspan="4">Balance Receivable</td><td>' . number_format((float) $ledger['receivable'], 2) . '</td></tr>'
            . '</table><div class="footer">Generated ' . date('d M Y, h:i A') . '</div>'
            . '</div></body></html>';
        Pdf::download($html, 'statement_customer_' . $customerId . '_' . date('Ymd') . '.pdf', 'A4', 'landscape');
    }

    // ------------------------------------------------------------- Excel
    /**
     * GET /api/export/excel?type=medicines|sales|purchases|customers|suppliers|expenses|inventory
     */
    public function excel(array $request): void
    {
        Excel::requireAvailable();
        $q = $this->query($request);
        $type = strtolower(trim((string) ($q['type'] ?? '')));

        match ($type) {
            'medicines' => $this->excelMedicines(),
            'sales'     => $this->excelSales($q),
            'purchases' => $this->excelPurchases($q),
            'customers' => $this->excelCustomers(),
            'suppliers' => $this->excelSuppliers(),
            'expenses'  => $this->excelExpenses($q),
            'inventory' => $this->excelInventory(),
            default     => throw new ApiException(
                'Unknown Excel type. Use medicines, sales, purchases, customers, suppliers, expenses, inventory.', 422
            ),
        };
    }

    private function excelMedicines(): void
    {
        $rows = Medicine::raw(
            'SELECT m.id, m.medicine_name AS name, m.generic_name,
                    c.name AS category, mf.name AS manufacturer,
                    m.barcode, m.packing AS unit,
                    (SELECT b2.purchase_price FROM medicine_batches b2
                      WHERE b2.medicine_id = m.id AND b2.deleted_at IS NULL ORDER BY b2.id DESC LIMIT 1) AS purchase_price,
                    (SELECT b2.sale_price FROM medicine_batches b2
                      WHERE b2.medicine_id = m.id AND b2.deleted_at IS NULL ORDER BY b2.id DESC LIMIT 1) AS sale_price,
                    (SELECT COALESCE(SUM(b3.quantity),0) FROM medicine_batches b3
                      WHERE b3.medicine_id = m.id AND b3.deleted_at IS NULL) AS stock,
                    (SELECT COALESCE(MIN(b3.minimum_stock),0) FROM medicine_batches b3
                      WHERE b3.medicine_id = m.id AND b3.deleted_at IS NULL) AS min_stock
             FROM medicines m LEFT JOIN medicine_categories c ON c.id = m.category_id
             LEFT JOIN manufacturers mf ON mf.id = m.manufacturer_id
             WHERE m.deleted_at IS NULL ORDER BY m.medicine_name ASC'
        );
        Excel::download('medicines_' . date('Ymd_His'),
            ['ID', 'Name', 'Generic', 'Category', 'Manufacturer', 'Barcode', 'Unit', 'Purchase Price', 'Sale Price', 'Stock Qty', 'Min Stock'],
            array_map('array_values', $rows), 'Medicines');
    }

    private function excelSales(array $q): void
    {
        $report = ReportService::sales($q + ['per_page' => 100000]);
        Excel::download('sales_' . date('Ymd_His'),
            ['ID', 'Invoice', 'Date', 'Customer', 'Subtotal', 'Discount', 'Tax', 'Grand Total', 'Status'],
            array_map(fn($s) => [
                $s['id'], $s['invoice_number'], $s['sale_date'], $s['customer_name'] ?? 'Walk-in',
                $s['subtotal'], $s['discount_amount'], $s['tax_amount'], $s['grand_total'], $s['payment_status'],
            ], $report['data']), 'Sales');
    }

    private function excelPurchases(array $q): void
    {
        $report = ReportService::purchases($q + ['per_page' => 100000]);
        Excel::download('purchases_' . date('Ymd_His'),
            ['ID', 'Invoice', 'Date', 'Supplier', 'Subtotal', 'Discount', 'Tax', 'Grand Total', 'Status'],
            array_map(fn($p) => [
                $p['id'], $p['invoice_number'], $p['purchase_date'], $p['supplier_name'] ?? '-',
                $p['subtotal'], $p['discount_amount'], $p['tax_amount'], $p['grand_total'], $p['payment_status'],
            ], $report['data']), 'Purchases');
    }

    private function excelCustomers(): void
    {
        $rows = Customer::raw('SELECT id, name, phone, address, cnic, reward_points, status FROM customers WHERE deleted_at IS NULL ORDER BY name ASC');
        Excel::download('customers_' . date('Ymd_His'),
            ['ID', 'Name', 'Phone', 'Address', 'CNIC', 'Reward Points', 'Status'],
            array_map('array_values', $rows), 'Customers');
    }

    private function excelSuppliers(): void
    {
        $rows = Supplier::raw('SELECT id, name, phone, email, address, contact_person, status FROM suppliers WHERE deleted_at IS NULL ORDER BY name ASC');
        Excel::download('suppliers_' . date('Ymd_His'),
            ['ID', 'Name', 'Phone', 'Email', 'Address', 'Contact Person', 'Status'],
            array_map('array_values', $rows), 'Suppliers');
    }

    private function excelExpenses(array $q): void
    {
        Validator::validate($q, ['from' => 'nullable|date', 'to' => 'nullable|date']);
        $report = ReportService::expenses($q + ['per_page' => 100000]);
        Excel::download('expenses_' . date('Ymd_His'),
            ['ID', 'Date', 'Title', 'Category', 'Amount', 'Notes'],
            array_map(fn($e) => [
                $e['id'], $e['expense_date'], $e['title'], $e['category_name'] ?? '-', $e['amount'], $e['note'],
            ], $report['data']), 'Expenses');
    }

    private function excelInventory(): void
    {
        $report = ReportService::inventory(['per_page' => 100000]);
        Excel::download('inventory_' . date('Ymd_His'),
            ['ID', 'Name', 'Barcode', 'Category', 'Stock Qty', 'Purchase Price', 'Sale Price', 'Value (Cost)', 'Value (Sale)'],
            array_map(fn($m) => [
                $m['id'], $m['name'], $m['barcode'], $m['category_name'] ?? '-',
                $m['stock'], $m['purchase_price'], $m['sale_price'], $m['stock_value_cost'], $m['stock_value_sale'],
            ], $report['data']), 'Inventory');
    }
}
