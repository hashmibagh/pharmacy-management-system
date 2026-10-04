<?php
declare(strict_types=1);

namespace Pharmacy\Models;

class Employee extends BaseModel
{
    protected static bool $softDeletes = true;
    protected static string $table = 'employees';

    public static function public(array $e): array
    {
        unset($e['password_hash']);
        return $e;
    }
}
