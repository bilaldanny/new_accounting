<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash rounding of a POS sale total. The company rule is `<mode>:<step>`: nearest, up or down to a step (0.5, 1, 5, 10 ...), e.g.
 * `nearest:5`. With no rule (the default) every total is what it was. The difference is kept on the sale
 * (`transactions.rounding_adjustment`) and, as the sale posts its final amount, lands in the sales revenue of that sale.
 */
class CashRounding
{
    public const MODES = ['nearest', 'up', 'down'];

    public static function supported(): bool
    {
        return Schema::hasColumn('company_settings', 'cash_rounding') && Schema::hasColumn('transactions', 'rounding_adjustment');
    }

    /**
     * @return array{mode: string, step: float}|null
     */
    public static function ruleFor(?int $companyId): ?array
    {
        if ($companyId === null || ! self::supported()) {
            return null;
        }

        return self::parse((string) DB::table('company_settings')->where('company_id', $companyId)->value('cash_rounding'));
    }

    /**
     * @return array{mode: string, step: float}|null
     */
    public static function parse(string $rule): ?array
    {
        if (! preg_match('/^(nearest|up|down):(\d+(?:\.\d+)?)$/', $rule, $matches) || (float) $matches[2] <= 0) {
            return null;
        }

        return ['mode' => $matches[1], 'step' => (float) $matches[2]];
    }

    /**
     * @param  array{mode: string, step: float}  $rule
     */
    public static function apply(float $amount, array $rule): float
    {
        $units = $amount / $rule['step'];
        $rounded = match ($rule['mode']) {
            'up' => ceil(round($units, 6)),
            'down' => floor(round($units, 6)),
            default => round($units),
        };

        return round($rounded * $rule['step'], 2);
    }
}
