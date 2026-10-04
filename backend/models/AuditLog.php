<?php
declare(strict_types=1);

namespace Pharmacy\Models;

/**
 * Immutable audit trail. Rows are insert-only: no update/delete helpers,
 * and no PUT/DELETE audit-log API endpoints exist.
 */
class AuditLog extends BaseModel
{
    protected static string $table = 'audit_logs';

    public static function record(
        ?int $userId,
        string $action,
        string $module,
        int|string|null $recordId,
        mixed $old,
        mixed $new,
        string $ip,
        string $userAgent
    ): void {
        static::create([
            'user_id'    => $userId,
            'action'     => $action,
            'module'     => $module,
            'record_id'  => $recordId,
            'old_data'   => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_data'   => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            'ip_address' => substr($ip, 0, 45),
            'user_agent' => substr($userAgent, 0, 512),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function list(int $page, int $perPage, array $filters): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['action'])) {
            $where[] = 'a.action = :action';
            $params[':action'] = $filters['action'];
        }
        if (!empty($filters['module'])) {
            $where[] = 'a.module = :module';
            $params[':module'] = $filters['module'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id = :uid';
            $params[':uid'] = (int) $filters['user_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'a.created_at >= :from';
            $params[':from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'a.created_at <= :to';
            $params[':to'] = $filters['to'] . ' 23:59:59';
        }
        $w = implode(' AND ', $where);
        $total = static::rawOne("SELECT COUNT(*) AS c FROM audit_logs a WHERE {$w}", $params)['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = static::raw(
            "SELECT a.*, u.name AS user_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE {$w} ORDER BY a.id DESC
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
