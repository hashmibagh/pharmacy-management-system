<?php
declare(strict_types=1);

namespace Pharmacy\Models;

class Supplier extends BaseModel
{
    protected static bool $softDeletes = true;
    protected static string $table = 'suppliers';

    /** Supplier ledger: purchases (+) and payments (−), running balance. */
    public static function ledger(int $supplierId, ?string $from = null, ?string $to = null): array
    {
        $params = [':sid' => $supplierId];
        $dateFilter = '';
        if ($from) {
            $dateFilter .= ' AND p.purchase_date >= :from';
            $params[':from'] = $from;
        }
        if ($to) {
            $dateFilter .= ' AND p.purchase_date <= :to';
            $params[':to'] = $to;
        }

        $purchases = static::raw(
            "SELECT p.id, p.purchase_date AS txn_date, CONCAT('Purchase #', p.invoice_number) AS description,
                    p.grand_total AS debit, 0 AS credit, 'purchase' AS type
             FROM purchases p
             WHERE p.supplier_id = :sid AND p.deleted_at IS NULL {$dateFilter}",
            $params
        );

        $pp = [':sid' => $supplierId];
        $payFilter = '';
        if ($from) {
            $payFilter .= ' AND pp.payment_date >= :from';
            $pp[':from'] = $from;
        }
        if ($to) {
            $payFilter .= ' AND pp.payment_date <= :to';
            $pp[':to'] = $to;
        }
        $payments = static::raw(
            "SELECT pp.id, pp.payment_date AS txn_date,
                    CONCAT('Payment (', pp.payment_method, ') #', pp.reference) AS description,
                    0 AS debit, pp.amount AS credit, 'payment' AS type
             FROM purchase_payments pp
             JOIN purchases p ON p.id = pp.purchase_id
             WHERE p.supplier_id = :sid {$payFilter}",
            $pp
        );

        $returns = static::raw(
            "SELECT pr.id, pr.return_date AS txn_date, CONCAT('Purchase return #', pr.id) AS description,
                    0 AS debit, pr.total_amount AS credit, 'return' AS type
             FROM purchase_returns pr
             JOIN purchases p ON p.id = pr.purchase_id
             WHERE p.supplier_id = :sid",
            [':sid' => $supplierId]
        );

        $rows = array_merge($purchases, $payments, $returns);
        usort($rows, fn($a, $b) => strcmp((string) $a['txn_date'], (string) $b['txn_date']));

        $balance = 0;
        foreach ($rows as &$r) {
            $balance += (float) $r['debit'] - (float) $r['credit'];
            $r['balance'] = round($balance, 2);
        }
        return ['entries' => $rows, 'outstanding' => round($balance, 2)];
    }
}
