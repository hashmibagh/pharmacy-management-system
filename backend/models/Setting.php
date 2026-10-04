<?php
declare(strict_types=1);

namespace Pharmacy\Models;

class Setting extends BaseModel
{
    protected static string $table = 'settings';

    /** @return array<string, string> key => value */
    public static function allKeyed(): array
    {
        $rows = static::raw('SELECT `key`, `value` FROM settings');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = $r['value'];
        }
        return $out;
    }

    public static function set(string $key, ?string $value): void
    {
        $exists = static::findBy('key', $key);
        if ($exists) {
            static::rawExec(
                'UPDATE settings SET `value` = :v, updated_at = NOW() WHERE `key` = :k',
                [':v' => $value, ':k' => $key]
            );
        } else {
            static::create(['key' => $key, 'value' => $value, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }
}
