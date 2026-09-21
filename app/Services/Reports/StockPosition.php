<?php

namespace App\Services\Reports;

use App\Services\StockMovements;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock in base units per variation, from StockMovements (the one definition of stock), for a branch or
 * for every branch, on a day or today. One grouped query, not one per product.
 */
class StockPosition
{
    /**
     * @return Collection<int, float> variation id => base-unit stock
     */
    public static function byVariation(?int $branchId = null, ?string $asOf = null): Collection
    {
        return DB::query()
            ->fromSub(StockMovements::query(['branch_id' => $branchId, 'as_of' => $asOf]), 'm')
            ->select('m.variation_id', DB::raw('sum(m.qty) as stock'))
            ->groupBy('m.variation_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->variation_id => round((float) $row->stock, 6)]);
    }
}
