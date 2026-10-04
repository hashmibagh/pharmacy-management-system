<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Setting;

class SettingsController extends BaseController
{
    public function index(array $request): void
    {
        Response::success(Setting::allKeyed());
    }

    /**
     * PUT body: { "settings": { "shop_name": "...", ... } }
     * Only whitelisted keys are writable.
     */
    public function update(array $request): void
    {
        $b = $this->body($request);
        $settings = $b['settings'] ?? $b;
        if (!is_array($settings) || !$settings) {
            throw new ApiException('Provide a "settings" object with key/value pairs.', 422);
        }

        $allowed = self::writableKeys();
        $unknown = array_diff(array_keys($settings), $allowed);
        if ($unknown) {
            throw new ApiException('Unknown setting keys: ' . implode(', ', $unknown), 422);
        }

        $old = Setting::allKeyed();
        foreach ($settings as $key => $value) {
            $errs = Validator::make(['v' => $value], ['v' => 'nullable|string|max:2000']);
            if ($errs) {
                throw new ApiException('Validation failed', 422, [$key => $errs['v']]);
            }
            Setting::set($key, $value === null ? null : (string) $value);
        }

        $this->audit($request, 'SETTINGS_UPDATED', 'settings', null, $old, $settings);
        Response::success(Setting::allKeyed(), 'Settings updated.');
    }

    /** @return string[] */
    public static function writableKeys(): array
    {
        return [
            'shop_name', 'shop_address', 'shop_phone', 'shop_email', 'shop_logo',
            'currency', 'currency_symbol', 'tax_rate', 'invoice_prefix',
            'low_stock_threshold_days', 'expiry_alert_days',
            'receipt_footer', 'terms_and_conditions',
        ];
    }
}
