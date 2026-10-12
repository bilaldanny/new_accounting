<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The business settings that had no home: the number format (decimals, separators, digit grouping, negative style), the POS
 * extras (cash drawer, shift rule) and the default language. Stored as one JSON document per company with a default for every key,
 * so a company that never saved anything gets exactly the defaults (which equal the format the application always used).
 *
 * The page (BusinessSettingsController) also shows the settings that live elsewhere (company profile, regional and POS pages) as a
 * read-only summary with links: one place to look, one place to change each value.
 */
class BusinessSettings
{
    public const DEFAULTS = [
        'number_format' => ['decimals' => 2, 'thousand' => ',', 'decimal' => '.', 'grouping' => 'western', 'negative' => 'minus'],
        'pos' => ['cash_drawer' => 'never', 'cash_drawer_pulse' => 'ESC p 0 25 250', 'require_open_shift' => false],
        'language' => ['default' => 'en'],
    ];

    public static function available(): bool
    {
        return Schema::hasTable('business_settings');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(int $companyId): array
    {
        return Cache::remember($this->cacheKey($companyId), 300, function () use ($companyId): array {
            $saved = self::available() ? (array) json_decode((string) DB::table('business_settings')->where('company_id', $companyId)->value('settings'), true) : [];
            $values = [];

            foreach (self::DEFAULTS as $group => $defaults) {
                $values[$group] = array_merge($defaults, array_intersect_key((array) ($saved[$group] ?? []), $defaults));
            }

            return $values;
        });
    }

    /**
     * @param  array<string, mixed>  $input  group => [key => value]; unknown groups and keys are ignored
     * @return array<string, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function save(int $companyId, array $input): array
    {
        $current = $this->all($companyId);
        $errors = [];

        foreach (self::DEFAULTS as $group => $defaults) {
            foreach (array_intersect_key((array) ($input[$group] ?? []), $defaults) as $key => $value) {
                $error = $this->check($group, $key, $value);

                if ($error !== null) {
                    $errors["{$group}.{$key}"] = [$error];

                    continue;
                }

                $current[$group][$key] = is_bool($defaults[$key]) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : (is_int($defaults[$key]) ? (int) $value : (string) $value);
            }
        }

        if (($current['number_format']['thousand'] ?? '') !== '' && $current['number_format']['thousand'] === $current['number_format']['decimal']) {
            $errors['number_format.thousand'] = ['The thousands and decimal separators must differ.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $raw = self::available() ? (array) json_decode((string) DB::table('business_settings')->where('company_id', $companyId)->value('settings'), true) : [];

        DB::table('business_settings')->updateOrInsert(['company_id' => $companyId], ['settings' => json_encode(array_merge($raw, $current)), 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget($this->cacheKey($companyId));

        return $current;
    }

    /**
     * Formats a number the way the company chose (the server side of the same rule the front end applies).
     */
    public function format(float $value, int $companyId, ?int $decimals = null): string
    {
        $f = $this->all($companyId)['number_format'];
        $decimals ??= (int) $f['decimals'];
        $negative = $value < 0;
        $fixed = number_format(abs($value), $decimals, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $fixed), 2, '');

        $whole = $f['grouping'] === 'indian' && strlen($whole) > 3
            ? preg_replace('/\B(?=(\d{2})+(?!\d))/', $f['thousand'], substr($whole, 0, -3)).$f['thousand'].substr($whole, -3)
            : preg_replace('/\B(?=(\d{3})+(?!\d))/', $f['thousand'], $whole);

        $text = $whole.($fraction !== '' ? $f['decimal'].$fraction : '');

        return $negative && (float) $fixed !== 0.0 ? ($f['negative'] === 'brackets' ? '('.$text.')' : '-'.$text) : $text;
    }

    private function check(string $group, string $key, mixed $value): ?string
    {
        return match ("{$group}.{$key}") {
            'number_format.decimals' => is_numeric($value) && (int) $value >= 0 && (int) $value <= 4 ? null : 'Choose 0 to 4 decimals.',
            'number_format.thousand' => in_array($value, [',', '.', ' ', ''], true) ? null : 'Choose a comma, a dot, a space or none.',
            'number_format.decimal' => in_array($value, ['.', ','], true) ? null : 'Choose a dot or a comma.',
            'number_format.grouping' => in_array($value, ['western', 'indian'], true) ? null : 'Choose western (1,234,567) or indian (12,34,567).',
            'number_format.negative' => in_array($value, ['minus', 'brackets'], true) ? null : 'Choose a minus sign or brackets.',
            'pos.cash_drawer' => in_array($value, ['never', 'cash_sale', 'any_sale'], true) ? null : 'Choose never, on a cash sale or on any sale.',
            'pos.cash_drawer_pulse' => is_string($value) && preg_match('/^[A-Za-z0-9 ]{1,40}$/', $value) === 1 ? null : 'Use letters, digits and spaces only.',
            'pos.require_open_shift' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true) ? null : 'Choose on or off.',
            'language.default' => in_array($value, ['en', 'ur'], true) ? null : 'Choose English or Urdu.',
            default => 'Unknown setting.',
        };
    }

    private function cacheKey(int $companyId): string
    {
        return "business_settings:{$companyId}";
    }
}
