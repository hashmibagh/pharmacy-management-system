<?php
declare(strict_types=1);

namespace Pharmacy\Helpers;

/**
 * Lightweight input validator. Returns field => [messages] errors.
 *
 * Supported rules (pipe separated):
 *   required | nullable | string | integer | numeric | boolean | array
 *   email | date | date_format:Y-m-d | min:3 | max:50 | in:a,b,c
 *   regex:/.../ | same:other_field
 */
final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules field => "required|string|max:100"
     * @return array<string, string[]> errors, empty when valid
     */
    public static function make(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            $ruleList = explode('|', $ruleString);
            $nullable = in_array('nullable', $ruleList, true);

            if (($value === null || $value === '') && !$nullable && in_array('required', $ruleList, true)) {
                $errors[$field][] = "The {$field} field is required.";
                continue;
            }
            if (($value === null || $value === '') && $nullable) {
                continue;
            }
            if ($value === null || $value === '') {
                continue; // not required and empty — skip other rules
            }

            foreach ($ruleList as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $fail = match ($name) {
                    'string'   => !is_string($value) && !is_numeric($value),
                    'integer'  => filter_var($value, FILTER_VALIDATE_INT) === false,
                    'numeric'  => !is_numeric($value),
                    'boolean'  => !in_array($value, [true, false, 0, 1, '0', '1'], true),
                    'array'    => !is_array($value),
                    'email'    => filter_var($value, FILTER_VALIDATE_EMAIL) === false,
                    'date'     => strtotime((string) $value) === false,
                    'min'      => is_numeric($value) ? ((float) $value < (float) $param) : (mb_strlen((string) $value) < (int) $param),
                    'max'      => is_numeric($value) ? ((float) $value > (float) $param) : (mb_strlen((string) $value) > (int) $param),
                    'in'       => !in_array((string) $value, explode(',', (string) $param), true),
                    'same'     => ($data[$param] ?? null) !== $value,
                    'regex'    => @preg_match((string) $param, (string) $value) !== 1,
                    'date_format' => self::badDateFormat((string) $value, (string) $param),
                    default    => false,
                };
                if ($fail) {
                    $errors[$field][] = self::message($field, $name, $param);
                }
            }
        }

        return $errors;
    }

    private static function badDateFormat(string $value, string $format): bool
    {
        $d = \DateTime::createFromFormat($format, $value);
        return !$d || $d->format($format) !== $value;
    }

    private static function message(string $field, string $rule, ?string $param): string
    {
        return match ($rule) {
            'string'  => "The {$field} must be a string.",
            'integer' => "The {$field} must be an integer.",
            'numeric' => "The {$field} must be a number.",
            'boolean' => "The {$field} must be true or false.",
            'array'   => "The {$field} must be an array.",
            'email'   => "The {$field} must be a valid email address.",
            'date'    => "The {$field} must be a valid date.",
            'date_format' => "The {$field} must match format {$param}.",
            'min'     => "The {$field} must be at least {$param}.",
            'max'     => "The {$field} must not exceed {$param}.",
            'in'      => "The {$field} must be one of: {$param}.",
            'same'    => "The {$field} must match {$param}.",
            'regex'   => "The {$field} format is invalid.",
            default   => "The {$field} is invalid.",
        };
    }

    /** Throw ApiException(422) when invalid — the common controller pattern. */
    public static function validate(array $data, array $rules): void
    {
        $errors = self::make($data, $rules);
        if ($errors) {
            throw new ApiException('Validation failed', 422, $errors);
        }
    }

    /** Keep only whitelisted keys (mass-assignment protection). */
    public static function only(array $data, array $allowed): array
    {
        return array_intersect_key($data, array_flip($allowed));
    }

    /** Normalized pagination params from query string. */
    public static function pagination(array $query, int $defaultPerPage = 20, int $maxPerPage = 200): array
    {
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? $defaultPerPage);
        $perPage = min(max(1, $perPage), $maxPerPage);
        return [$page, $perPage];
    }

    /** Basic XSS-safe string cleanup for stored text (names, notes). */
    public static function cleanString(mixed $value): string
    {
        return trim(strip_tags((string) $value));
    }
}
