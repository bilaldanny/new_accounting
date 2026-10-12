<?php

namespace App\Services\Hrm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * HR and payroll settings of a company, one JSON document with a default for every key. Rates of contributions and taxes are NOT
 * here and never defaulted: they are rows the company's accountant enters (statutory_rates, tax_slabs) with the date they apply from.
 * Payroll posting to the ledger is off until a company turns it on and maps its accounts.
 */
class HrSettings
{
    public const DEFAULTS = [
        'days_basis' => 'calendar',
        'fixed_days' => 30,
        'standard_hours_per_day' => 8.0,
        'assume_present_without_attendance' => true,
        'deduct_late' => false,
        'late_deduction_factor' => 1.0,
        'deduct_early' => false,
        'overtime_enabled' => true,
        'overtime_multiplier' => 1.5,
        'overtime_min_minutes' => 30,
        'gratuity_days_per_year' => 0.0,
        'document_alert_days' => 30,
        'posting_enabled' => false,
        'accounts' => ['salary_expense' => null, 'salary_payable' => null, 'tax_payable' => null, 'statutory_payable' => null, 'loan_receivable' => null, 'accrual_expense' => null, 'accrual_liability' => null],
    ];

    public static function available(): bool
    {
        return Schema::hasTable('hr_settings');
    }

    /**
     * @return array<string, mixed>
     */
    public function all(int $companyId): array
    {
        $saved = self::available() ? (array) json_decode((string) DB::table('hr_settings')->where('company_id', $companyId)->value('settings'), true) : [];
        $values = array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        $values['accounts'] = array_merge(self::DEFAULTS['accounts'], array_intersect_key((array) ($saved['accounts'] ?? []), self::DEFAULTS['accounts']));

        return $values;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function save(int $companyId, array $input): array
    {
        $current = $this->all($companyId);
        $errors = [];

        foreach (array_intersect_key($input, self::DEFAULTS) as $key => $value) {
            if ($key === 'accounts') {
                foreach (array_intersect_key((array) $value, self::DEFAULTS['accounts']) as $slot => $accountId) {
                    if ($accountId === null || $accountId === '') {
                        $current['accounts'][$slot] = null;

                        continue;
                    }

                    if (! DB::table('chart_of_accounts')->where('company_id', $companyId)->where('id', (int) $accountId)->exists()) {
                        $errors["accounts.{$slot}"] = ['Choose an account of this company.'];

                        continue;
                    }

                    $current['accounts'][$slot] = (int) $accountId;
                }

                continue;
            }

            $default = self::DEFAULTS[$key];
            $error = match ($key) {
                'days_basis' => in_array($value, ['calendar', 'fixed'], true) ? null : 'Choose calendar days or a fixed number of days.',
                'fixed_days' => is_numeric($value) && (int) $value >= 20 && (int) $value <= 31 ? null : 'Use a number from 20 to 31.',
                'standard_hours_per_day' => is_numeric($value) && (float) $value >= 1 && (float) $value <= 16 ? null : 'Use hours from 1 to 16.',
                'late_deduction_factor', 'overtime_multiplier' => is_numeric($value) && (float) $value >= 0 && (float) $value <= 5 ? null : 'Use a factor from 0 to 5.',
                'overtime_min_minutes' => is_numeric($value) && (int) $value >= 0 && (int) $value <= 240 ? null : 'Use minutes from 0 to 240.',
                'gratuity_days_per_year' => is_numeric($value) && (float) $value >= 0 && (float) $value <= 60 ? null : 'Use days from 0 to 60.',
                'document_alert_days' => is_numeric($value) && (int) $value >= 1 && (int) $value <= 365 ? null : 'Use days from 1 to 365.',
                default => is_bool($default) && (is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) ? null : (is_bool($default) ? 'Choose on or off.' : 'Unknown setting.'),
            };

            if ($error !== null) {
                $errors[$key] = [$error];

                continue;
            }

            $current[$key] = is_bool($default) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : (is_int($default) ? (int) $value : (is_float($default) ? (float) $value : (string) $value));
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::table('hr_settings')->updateOrInsert(['company_id' => $companyId], ['settings' => json_encode($current), 'updated_at' => now(), 'created_at' => now()]);

        return $current;
    }
}
