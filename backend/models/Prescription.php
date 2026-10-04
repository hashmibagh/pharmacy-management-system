<?php
declare(strict_types=1);

namespace Pharmacy\Models;

class Prescription extends BaseModel
{
    protected static string $table = 'prescriptions';

    public static function findDetailed(int $id): ?array
    {
        $p = static::rawOne(
            'SELECT pr.*, pr.patient_name AS customer_name, pr.patient_phone AS customer_phone,
                    u.name AS created_by_name
             FROM prescriptions pr
             LEFT JOIN users u ON u.id = pr.created_by
             WHERE pr.id = :id LIMIT 1',
            [':id' => $id]
        );
        if (!$p) {
            return null;
        }
        $p['items'] = static::raw(
            'SELECT * FROM prescription_items WHERE prescription_id = :pid ORDER BY id ASC',
            [':pid' => $id]
        );
        return $p;
    }
}
