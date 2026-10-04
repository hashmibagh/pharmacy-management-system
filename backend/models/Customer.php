<?php
declare(strict_types=1);

namespace Pharmacy\Models;

class Customer extends BaseModel
{
    protected static bool $softDeletes = true;
    protected static string $table = 'customers';

    /** Customer ledger: sales (+) , payments (−), sale returns (−). */
    public static function ledger(int $customerId, ?string $from = null, ?string $to = null): array
    {
        $params = [':cid' => $customerId];
        $df = '';
        if ($from) {
            $df .= ' AND s.sale_date >= :from';
            $params[':from'] = $from;
        }
        if ($to) {
            $df .= ' AND s.sale_date <= :to';
            $params[':to'] = $to;
        }

        $sales = static::raw(
            "SELECT s.id, s.sale_date AS txn_date, CONCAT('Sale #', s.invoice_number) AS description,
                    s.grand_total AS debit, 0 AS credit, 'sale' AS type
             FROM sales s WHERE s.customer_id = :cid AND s.deleted_at IS NULL {$df}",
            $params
        );

        $pp = [':cid' => $customerId];
        $pf = '';
        if ($from) {
            $pf .= ' AND sp.payment_date >= :from';
            $pp[':from'] = $from;
        }
        if ($to) {
            $pf .= ' AND sp.payment_date <= :to';
            $pp[':to'] = $to;
        }
        $payments = static::raw(
            "SELECT sp.id, sp.payment_date AS txn_date,
                    CONCAT('Receipt (', sp.payment_method, ')') AS description,
                    0 AS debit, sp.amount AS credit, 'payment' AS type
             FROM sale_payments sp
             JOIN sales s ON s.id = sp.sale_id
             WHERE s.customer_id = :cid {$pf}",
            $pp
        );

        $returns = static::raw(
            "SELECT sr.id, sr.return_date AS txn_date, CONCAT('Sale return #', sr.id) AS description,
                    0 AS debit, sr.total_amount AS credit, 'return' AS type
             FROM sale_returns sr
             JOIN sales s ON s.id = sr.sale_id
             WHERE s.customer_id = :cid",
            [':cid' => $customerId]
        );

        $rows = array_merge($sales, $payments, $returns);
        usort($rows, fn($a, $b) => strcmp((string) $a['txn_date'], (string) $b['txn_date']));

        $balance = 0;
        foreach ($rows as &$r) {
            $balance += (float) $r['debit'] - (float) $r['credit'];
            $r['balance'] = round($balance, 2);
        }
        return ['entries' => $rows, 'receivable' => round($balance, 2)];
    }
}
