<?php
declare(strict_types=1);

namespace Pharmacy\Models;

/**
 * Immutable audit trail. No update/delete methods are exposed on purpose —
 * there are no PUT/DELETE audit-log endpoints either.
 */
class StockTransaction extends BaseModel
{
    protected static string $table = 'stock_transactions';

    public static function history(int $page, int $perPage, array $filters): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['medicine_id'])) {
            $where[] = 'st.medicine_id = :mid';
            $params[':mid'] = (int) $filters['medicine_id'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'st.transaction_type = :type';
            $params[':type'] = $filters['type'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'st.created_at >= :from';
            $params[':from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'st.created_at <= :to';
            $params[':to'] = $filters['to'] . ' 23:59:59';
        }
        $w = implode(' AND ', $where);
        $total = static::rawOne("SELECT COUNT(*) AS c FROM stock_transactions st WHERE {$w}", $params)['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = static::raw(
            "SELECT st.*, st.transaction_type AS type, st.quantity_change AS qty,
                    st.notes AS note,
                    m.medicine_name, m.medicine_name AS name, u.name AS user_name
             FROM stock_transactions st
             LEFT JOIN medicines m ON m.id = st.medicine_id
             LEFT JOIN users u ON u.id = st.created_by
             WHERE {$w} ORDER BY st.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page, 'per_page' => $perPage,
                'total' => (int) $total, 'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }
}
