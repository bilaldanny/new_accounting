<?php

namespace App\Services\Reports;

use App\Services\StockMovements;
use Illuminate\Support\Facades\DB;

/**
 * What the stock on hand is worth on a day: every variation's stock (StockMovements, the one definition
 * of stock, in base units, up to and including the day) times its weighted average purchase cost on that
 * day (AverageCost, the cost Item Profit & Loss and the Stock Report use).
 *
 * It is the same figure as the total stock value of the Stock Report for that day, so the two always agree.
 * A variation with stock but no purchase up to the day has no cost; it is left out of the value and counted
 * in `uncosted` rather than valued at zero silently. Stock below zero (more sold than received) is valued
 * like any other, so it lowers the total, as it does in the Stock Report. Deleted products are not counted.
 */
class StockValuation
{
    /**
     * @return array{value: float, uncosted: int}
     */
    public function at(int $companyId, ?int $branchId, string $asOf): array
    {
        $stock = DB::query()
            ->fromSub(StockMovements::query(['branch_id' => $branchId, 'as_of' => $asOf]), 'm')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->groupBy('m.variation_id')
            ->select('m.variation_id', DB::raw('sum(m.qty) as stock'))
            ->get();

        $costs = AverageCost::byVariation($companyId, $asOf);
        $value = 0.0;
        $uncosted = 0;

        foreach ($stock as $row) {
            $quantity = round((float) $row->stock, 6);

            if ($quantity == 0.0) {
                continue;
            }

            $cost = $costs[(int) $row->variation_id] ?? null;

            if ($cost === null) {
                $uncosted++;

                continue;
            }

            $value += $quantity * $cost;
        }

        return ['value' => round($value, 2), 'uncosted' => $uncosted];
    }
}
