<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a sale does when it would take a product below zero stock. Without a company mode (and with no product override) it
 * is what it always was: the POS refuses, the normal sale form saves and warns. A company mode of `allow`, `warn` or `block`
 * applies to every sale form; a product's own `negative_stock` (allow / block) wins over the company.
 *
 * `allow` and `warn` both save the sale; `allow` is the explicit "we sell before we receive" choice, so the POS accepts it too.
 */
class NegativeStockRules
{
    public const MODES = ['allow', 'warn', 'block'];

    private static ?bool $tracked = null;

    public static function supported(): bool
    {
        return self::$tracked ??= Schema::hasColumn('company_settings', 'negative_stock_mode') && Schema::hasColumn('products', 'negative_stock');
    }

    public static function forgetSchemaMemo(): void
    {
        self::$tracked = null;
    }

    /**
     * @param  list<array{product_id: int, variation_id: int, product_name: string, requested: float, available: float}>  $shortages
     * @return array{blocking: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function split(array $shortages, ?int $companyId, bool $isPos): array
    {
        if ($shortages === []) {
            return ['blocking' => [], 'warnings' => []];
        }

        $company = null;
        $overrides = [];

        if (self::supported()) {
            $company = $companyId === null ? null : DB::table('company_settings')->where('company_id', $companyId)->value('negative_stock_mode');
            $overrides = DB::table('products')->whereIn('id', array_column($shortages, 'product_id'))->whereNotNull('negative_stock')->pluck('negative_stock', 'id')->all();
        }

        $blocking = [];
        $warnings = [];

        foreach ($shortages as $shortage) {
            $mode = $overrides[$shortage['product_id']] ?? $company;
            $blocks = match (true) {
                $mode === 'block' => true,
                $mode === 'allow' => false,
                default => $isPos,
            };

            if ($blocks) {
                $blocking[] = $shortage;
            } else {
                $warnings[] = $shortage;
            }
        }

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }
}
