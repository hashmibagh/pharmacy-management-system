<?php
declare(strict_types=1);

namespace Pharmacy\Services;

use Pharmacy\Helpers\Validator;
use Pharmacy\Models\BaseModel;

/**
 * Read-only aggregate queries backing ReportController.
 * Every report supports from/to date filters + search + pagination.
 * Soft-deleted rows (deleted_at IS NULL) are excluded everywhere.
 */
final class ReportService extends BaseModel
{
    protected static string $table = 'sales'; // unused; only raw() helpers are used

    /** Frontend sends `q`; older callers send `search`. Accept both. */
    private static function searchTerm(array $q): string
    {
        return trim((string) ($q['q'] ?? $q['search'] ?? ''));
    }

    private static function dateFilter(string $column, ?string $from, ?string $to, array &$params): string
    {
        $sql = '';
        if ($from) {
            $sql .= " AND {$column} >= :from";
            $params[':from'] = $from . ' 00:00:00';
        }
        if ($to) {
            $sql .= " AND {$column} <= :to";
            $params[':to'] = $to . ' 23:59:59';
        }
        return $sql;
    }

    private static function paginateRaw(string $countSql, string $dataSql, array $params, int $page, int $perPage): array
    {
        $total = (int) (static::rawOne($countSql, $params)['c'] ?? 0);
        $data = static::raw(
            $dataSql . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $params
        );
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page, 'per_page' => $perPage,
                'total' => $total, 'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    public static function sales(array $q): array
    {
        [$page, $perPage] = Validator::pagination($q);
        $params = [];
        $where = 's.deleted_at IS NULL' . self::dateFilter('s.sale_date', $q['from'] ?? null, $q['to'] ?? null, $params);
        $search = self::searchTerm($q);
        if ($search !== '') {
            $where .= ' AND (s.invoice_number LIKE :s OR c.name LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        if (!empty($q['payment_status'])) {
            $where .= ' AND s.payment_status = :ps';
            $params[':ps'] = $q['payment_status'];
        }
        $summary = static::rawOne(
            "SELECT COUNT(*) AS count, COALESCE(SUM(grand_total),0) AS total,
                    COALESCE(SUM(discount_amount),0) AS discount, COALESCE(SUM(tax_amount),0) AS tax
             FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE {$where}",
            $params
        );
        $rows = self::paginateRaw(
            "SELECT COUNT(*) AS c FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE {$where}",
            "SELECT s.*, c.name AS customer_name FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE {$where} ORDER BY s.sale_date DESC, s.id DESC",
            $params, $page, $perPage
        );
        $rows['summary'] = $summary;
        return $rows;
    }

    public static function purchases(array $q): array
    {
        [$page, $perPage] = Validator::pagination($q);
        $params = [];
        $where = 'p.deleted_at IS NULL' . self::dateFilter('p.purchase_date', $q['from'] ?? null, $q['to'] ?? null, $params);
        $search = self::searchTerm($q);
        if ($search !== '') {
            $where .= ' AND (p.invoice_number LIKE :s OR s.name LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        $summary = static::rawOne(
            "SELECT COUNT(*) AS count, COALESCE(SUM(grand_total),0) AS total,
                    COALESCE(SUM(discount_amount),0) AS discount, COALESCE(SUM(tax_amount),0) AS tax
             FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE {$where}",
            $params
        );
        $rows = self::paginateRaw(
            "SELECT COUNT(*) AS c FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE {$where}",
            "SELECT p.*, s.name AS supplier_name FROM purchases p
             LEFT JOIN suppliers s ON s.id = p.supplier_id
             WHERE {$where} ORDER BY p.purchase_date DESC, p.id DESC",
            $params, $page, $perPage
        );
        $rows['summary'] = $summary;
        return $rows;
    }

    /**
     * Profit = (sale revenue − COGS) − expenses, over the period.
     * COGS = SUM(sale_items.quantity × medicine_batches.purchase_price)
     * via the batch each item was sold from.
     */
    public static function profit(array $q): array
    {
        $params = [];
        $salesWhere = 's.deleted_at IS NULL'
            . self::dateFilter('s.sale_date', $q['from'] ?? null, $q['to'] ?? null, $params);
        $sales = static::rawOne(
            "SELECT COALESCE(SUM(s.grand_total),0) AS revenue,
                    COALESCE(SUM(si.quantity * mb.purchase_price),0) AS cogs,
                    COALESCE(SUM(s.discount_amount),0) AS discount
             FROM sales s
             LEFT JOIN sale_items si ON si.sale_id = s.id
             LEFT JOIN medicine_batches mb ON mb.id = si.batch_id
             WHERE {$salesWhere}",
            $params
        );

        $eParams = [];
        $expWhere = 'deleted_at IS NULL'
            . self::dateFilter('expense_date', $q['from'] ?? null, $q['to'] ?? null, $eParams);
        $expenses = static::rawOne(
            "SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE {$expWhere}",
            $eParams
        );

        $revenue  = (float) ($sales['revenue'] ?? 0);
        $cogs     = (float) ($sales['cogs'] ?? 0);
        $discount = (float) ($sales['discount'] ?? 0);
        $expTotal = (float) ($expenses['total'] ?? 0);
        $gross    = $revenue - $cogs - $discount;

        return [
            'from' => $q['from'] ?? null,
            'to'   => $q['to'] ?? null,
            'revenue' => round($revenue, 2),
            'cost_of_goods_sold' => round($cogs, 2),
            'discounts' => round($discount, 2),
            'gross_profit' => round($gross, 2),
            'expenses' => round($expTotal, 2),
            'net_profit' => round($gross - $expTotal, 2),
        ];
    }

    public static function inventory(array $q): array
    {
        [$page, $perPage] = Validator::pagination($q);
        $params = [];
        $where = 'm.deleted_at IS NULL';
        $search = self::searchTerm($q);
        if ($search !== '') {
            $where .= ' AND (m.medicine_name LIKE :s OR m.barcode LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        if (!empty($q['category_id'])) {
            $where .= ' AND m.category_id = :cat';
            $params[':cat'] = (int) $q['category_id'];
        }
        $countSql = "SELECT COUNT(*) AS c FROM medicines m WHERE {$where}";
        $dataSql = "SELECT m.id, m.medicine_name AS name, m.barcode,
                           (SELECT COALESCE(SUM(b.quantity),0) FROM medicine_batches b
                             WHERE b.medicine_id = m.id AND b.deleted_at IS NULL) AS stock,
                           (SELECT b2.purchase_price FROM medicine_batches b2
                             WHERE b2.medicine_id = m.id AND b2.deleted_at IS NULL
                             ORDER BY b2.id DESC LIMIT 1) AS purchase_price,
                           (SELECT b3.sale_price FROM medicine_batches b3
                             WHERE b3.medicine_id = m.id AND b3.deleted_at IS NULL
                             ORDER BY b3.id DESC LIMIT 1) AS sale_price,
                           (SELECT COALESCE(SUM(b.quantity * b.purchase_price),0) FROM medicine_batches b
                             WHERE b.medicine_id = m.id AND b.deleted_at IS NULL) AS stock_value_cost,
                           (SELECT COALESCE(SUM(b.quantity * b.sale_price),0) FROM medicine_batches b
                             WHERE b.medicine_id = m.id AND b.deleted_at IS NULL) AS stock_value_sale,
                           c.name AS category_name
                    FROM medicines m LEFT JOIN medicine_categories c ON c.id = m.category_id
                    WHERE {$where} ORDER BY m.medicine_name ASC";
        $rows = self::paginateRaw($countSql, $dataSql, $params, $page, $perPage);
        $totals = static::rawOne(
            "SELECT COALESCE(SUM(b.quantity * b.purchase_price),0) AS value_cost,
                    COALESCE(SUM(b.quantity * b.sale_price),0) AS value_sale,
                    COALESCE(SUM(b.quantity),0) AS units
             FROM medicine_batches b JOIN medicines m ON m.id = b.medicine_id
             WHERE b.deleted_at IS NULL AND {$where}",
            $params
        );
        $rows['summary'] = $totals;
        return $rows;
    }

    public static function expiry(array $q): array
    {
        [$page, $perPage] = Validator::pagination($q);
        $days = (int) ($q['days'] ?? 90);
        $params = [':days' => $days];
        $where = 'b.quantity > 0 AND b.expiry_date IS NOT NULL AND b.deleted_at IS NULL
                  AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)';
        if (!empty($q['expired_only'])) {
            $where = 'b.quantity > 0 AND b.expiry_date IS NOT NULL AND b.deleted_at IS NULL
                       AND b.expiry_date < CURDATE()';
        }
        $countSql = "SELECT COUNT(*) AS c FROM medicine_batches b WHERE {$where}";
        $dataSql = "SELECT b.*, b.quantity AS qty, b.batch_number AS batch_no,
                           b.minimum_stock AS min_stock, m.medicine_name,
                           DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry
                    FROM medicine_batches b JOIN medicines m ON m.id = b.medicine_id
                    WHERE {$where} ORDER BY b.expiry_date ASC";
        return self::paginateRaw($countSql, $dataSql, $params, $page, $perPage);
    }

    public static function expenses(array $q): array
    {
        [$page, $perPage] = Validator::pagination($q);
        $params = [];
        $where = 'e.deleted_at IS NULL' . self::dateFilter('e.expense_date', $q['from'] ?? null, $q['to'] ?? null, $params);
        if (!empty($q['category_id']) || !empty($q['expense_category_id'])) {
            $where .= ' AND e.expense_category_id = :cat';
            $params[':cat'] = (int) ($q['category_id'] ?? $q['expense_category_id']);
        }
        $search = self::searchTerm($q);
        if ($search !== '') {
            $where .= ' AND e.description LIKE :s';
            $params[':s'] = '%' . $search . '%';
        }
        $summary = static::rawOne(
            "SELECT COUNT(*) AS count, COALESCE(SUM(amount),0) AS total
             FROM expenses e WHERE {$where}",
            $params
        );
        $byCategory = static::raw(
            "SELECT ec.name AS category, COALESCE(SUM(e.amount),0) AS total
             FROM expenses e LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
             WHERE {$where} GROUP BY ec.name ORDER BY total DESC",
            $params
        );
        $rows = self::paginateRaw(
            "SELECT COUNT(*) AS c FROM expenses e WHERE {$where}",
            "SELECT e.*, e.description AS title, e.description AS note, e.expense_date AS date,
                    ec.name AS category_name FROM expenses e
             LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
             WHERE {$where} ORDER BY e.expense_date DESC, e.id DESC",
            $params, $page, $perPage
        );
        $rows['summary'] = $summary;
        $rows['by_category'] = $byCategory;
        return $rows;
    }

    /** Generic custom report over a whitelisted set of entities. */
    public static function custom(string $entity, array $q): array
    {
        return match ($entity) {
            'sales'     => self::sales($q),
            'purchases' => self::purchases($q),
            'inventory' => self::inventory($q),
            'expiry'    => self::expiry($q),
            'expenses'  => self::expenses($q),
            default     => throw new \Pharmacy\Helpers\ApiException(
                'Unknown report entity. Allowed: sales, purchases, inventory, expiry, expenses.', 422
            ),
        };
    }

    // ============================================================ dashboard
    /**
     * Profit over a sale_date window: revenue − SUM(qty × batch purchase_price).
     */
    private static function profitFor(?string $from, ?string $to): float
    {
        $params = [];
        $where = 's.deleted_at IS NULL';
        if ($from) {
            $where .= ' AND s.sale_date >= :from';
            $params[':from'] = $from;
        }
        if ($to) {
            $where .= ' AND s.sale_date <= :to';
            $params[':to'] = $to;
        }
        $row = static::rawOne(
            "SELECT COALESCE(SUM(si.quantity * si.sale_price),0) AS revenue,
                    COALESCE(SUM(si.quantity * mb.purchase_price),0) AS cogs
             FROM sales s
             JOIN sale_items si ON si.sale_id = s.id
             LEFT JOIN medicine_batches mb ON mb.id = si.batch_id
             WHERE {$where}",
            $params
        );
        return round((float) ($row['revenue'] ?? 0) - (float) ($row['cogs'] ?? 0), 2);
    }

    /**
     * Full dashboard payload for GET /api/dashboard (and
     * /api/reports/custom?summary=dashboard).
     */
    public static function dashboard(): array
    {
        $todayStart = date('Y-m-d') . ' 00:00:00';
        $todayEnd   = date('Y-m-d') . ' 23:59:59';
        $monthStart = date('Y-m-01') . ' 00:00:00';

        // ---- sales KPIs
        $kpis = static::rawOne(
            "SELECT COALESCE(SUM(CASE WHEN s.sale_date >= :ts THEN s.grand_total END),0) AS today,
                    COALESCE(SUM(CASE WHEN s.sale_date >= :ms THEN s.grand_total END),0) AS month,
                    COALESCE(SUM(s.due_amount),0) AS pending
             FROM sales s WHERE s.deleted_at IS NULL",
            [':ts' => $todayStart, ':ms' => $monthStart]
        );

        // ---- inventory KPIs
        $inv = static::rawOne(
            "SELECT COUNT(DISTINCT m.id) AS total,
                    COALESCE(SUM(b.quantity * b.purchase_price),0) AS value
             FROM medicines m
             LEFT JOIN medicine_batches b ON b.medicine_id = m.id AND b.deleted_at IS NULL
             WHERE m.deleted_at IS NULL"
        );
        $lowStock = (int) (static::rawOne(
            "SELECT COUNT(*) AS c FROM (
               SELECT m.id
               FROM medicines m
               LEFT JOIN medicine_batches b ON b.medicine_id = m.id AND b.deleted_at IS NULL
               WHERE m.status = 'active' AND m.deleted_at IS NULL
               GROUP BY m.id
               HAVING COALESCE(SUM(b.quantity),0) > 0
                  AND COALESCE(MIN(b.minimum_stock),0) > 0
                  AND COALESCE(SUM(b.quantity),0) <= COALESCE(MIN(b.minimum_stock),0)
             ) t"
        )['c'] ?? 0);
        $outOfStock = (int) (static::rawOne(
            "SELECT COUNT(*) AS c FROM (
               SELECT m.id
               FROM medicines m
               LEFT JOIN medicine_batches b ON b.medicine_id = m.id AND b.deleted_at IS NULL
               WHERE m.status = 'active' AND m.deleted_at IS NULL
               GROUP BY m.id
               HAVING COALESCE(SUM(b.quantity),0) <= 0
             ) t"
        )['c'] ?? 0);
        $expCounts = static::rawOne(
            "SELECT COALESCE(SUM(CASE WHEN b.expiry_date < CURDATE() THEN 1 ELSE 0 END),0) AS expired,
                    COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN 1 ELSE 0 END),0) AS expiring
             FROM medicine_batches b
             WHERE b.quantity > 0 AND b.expiry_date IS NOT NULL
               AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
               AND b.deleted_at IS NULL"
        );
        $expiringList = static::raw(
            "SELECT b.id, m.medicine_name AS name, b.expiry_date
             FROM medicine_batches b JOIN medicines m ON m.id = b.medicine_id
             WHERE b.quantity > 0 AND b.expiry_date IS NOT NULL
               AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
               AND b.deleted_at IS NULL
             ORDER BY b.expiry_date ASC LIMIT 10"
        );

        // ---- customers & suppliers
        $cust = static::rawOne(
            "SELECT COUNT(*) AS total FROM customers WHERE deleted_at IS NULL"
        );
        $custDue = (float) (static::rawOne(
            "SELECT COALESCE(SUM(s.due_amount),0) AS due FROM sales s
             WHERE s.deleted_at IS NULL AND s.customer_id IS NOT NULL"
        )['due'] ?? 0);
        $recentCustomers = static::raw(
            "SELECT c.id, c.name,
                    COALESCE((SELECT SUM(s.due_amount) FROM sales s
                              WHERE s.customer_id = c.id AND s.deleted_at IS NULL),0) AS due
             FROM customers c WHERE c.deleted_at IS NULL
             ORDER BY c.created_at DESC LIMIT 5"
        );
        $supp = static::rawOne(
            "SELECT COUNT(*) AS total FROM suppliers WHERE deleted_at IS NULL"
        );
        $suppDue = (float) (static::rawOne(
            "SELECT COALESCE(SUM(p.due_amount),0) AS due FROM purchases p WHERE p.deleted_at IS NULL"
        )['due'] ?? 0);

        // ---- sales last 30 days (with per-day profit)
        $daily = static::raw(
            "SELECT DATE(s.sale_date) AS date,
                    COALESCE(SUM(s.grand_total),0) AS total,
                    COALESCE(SUM(si.quantity * si.sale_price),0) AS revenue,
                    COALESCE(SUM(si.quantity * mb.purchase_price),0) AS cogs
             FROM sales s
             LEFT JOIN sale_items si ON si.sale_id = s.id
             LEFT JOIN medicine_batches mb ON mb.id = si.batch_id
             WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND s.deleted_at IS NULL
             GROUP BY DATE(s.sale_date) ORDER BY date ASC"
        );
        $salesLast30 = array_map(fn($d) => [
            'date'   => $d['date'],
            'total'  => round((float) $d['total'], 2),
            'profit' => round((float) $d['revenue'] - (float) $d['cogs'], 2),
        ], $daily);

        // ---- monthly comparison: sales vs purchases (last 6 months)
        $mSales = static::raw(
            "SELECT DATE_FORMAT(s.sale_date,'%Y-%m') AS month, COALESCE(SUM(s.grand_total),0) AS sales
             FROM sales s
             WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND s.deleted_at IS NULL
             GROUP BY DATE_FORMAT(s.sale_date,'%Y-%m')"
        );
        $mPurch = static::raw(
            "SELECT DATE_FORMAT(p.purchase_date,'%Y-%m') AS month, COALESCE(SUM(p.grand_total),0) AS purchases
             FROM purchases p
             WHERE p.purchase_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND p.deleted_at IS NULL
             GROUP BY DATE_FORMAT(p.purchase_date,'%Y-%m')"
        );
        $byMonth = [];
        foreach ($mSales as $r) {
            $byMonth[$r['month']] = ['month' => $r['month'], 'sales' => round((float) $r['sales'], 2), 'purchases' => 0.0];
        }
        foreach ($mPurch as $r) {
            if (!isset($byMonth[$r['month']])) {
                $byMonth[$r['month']] = ['month' => $r['month'], 'sales' => 0.0, 'purchases' => 0.0];
            }
            $byMonth[$r['month']]['purchases'] = round((float) $r['purchases'], 2);
        }
        ksort($byMonth);
        $monthlyComparison = array_values($byMonth);

        // ---- sales by category
        $byCategory = static::raw(
            "SELECT COALESCE(mc.name,'Uncategorized') AS category,
                    COALESCE(SUM(si.quantity * si.sale_price),0) AS total
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             JOIN medicines m ON m.id = si.medicine_id
             LEFT JOIN medicine_categories mc ON mc.id = m.category_id
             WHERE s.deleted_at IS NULL
             GROUP BY mc.name ORDER BY total DESC"
        );
        $byCategory = array_map(fn($r) => [
            'category' => $r['category'],
            'total'    => round((float) $r['total'], 2),
        ], $byCategory);

        // ---- sales by payment method
        $byPayment = static::raw(
            "SELECT s.payment_method AS method, COALESCE(SUM(s.grand_total),0) AS total
             FROM sales s WHERE s.deleted_at IS NULL
             GROUP BY s.payment_method ORDER BY total DESC"
        );
        $byPayment = array_map(fn($r) => [
            'method' => $r['method'],
            'total'  => round((float) $r['total'], 2),
        ], $byPayment);

        return [
            'sales' => [
                'today'            => round((float) ($kpis['today'] ?? 0), 2),
                'today_profit'     => self::profitFor($todayStart, $todayEnd),
                'month'            => round((float) ($kpis['month'] ?? 0), 2),
                'month_profit'     => self::profitFor($monthStart, null),
                'pending_payments' => round((float) ($kpis['pending'] ?? 0), 2),
            ],
            'inventory' => [
                'total'        => (int) ($inv['total'] ?? 0),
                'low_stock'    => $lowStock,
                'out_of_stock' => $outOfStock,
                'expiring_soon'=> (int) ($expCounts['expiring'] ?? 0),
                'expired'      => (int) ($expCounts['expired'] ?? 0),
                'value'        => round((float) ($inv['value'] ?? 0), 2),
                'expiring_list'=> $expiringList,
            ],
            'customers' => [
                'total'  => (int) ($cust['total'] ?? 0),
                'due'    => round($custDue, 2),
                'recent' => array_map(fn($r) => [
                    'id' => (int) $r['id'], 'name' => $r['name'], 'due' => round((float) $r['due'], 2),
                ], $recentCustomers),
            ],
            'suppliers' => [
                'total' => (int) ($supp['total'] ?? 0),
                'due'   => round($suppDue, 2),
            ],
            'sales_last_30_days'     => $salesLast30,
            'monthly_comparison'     => $monthlyComparison,
            'sales_by_category'      => $byCategory,
            'sales_by_payment_method'=> $byPayment,
        ];
    }
}
