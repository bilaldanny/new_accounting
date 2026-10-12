<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The weighted average purchase cost of a product variation per base unit.
 *
 * The app does not keep a cost per sale (the FIFO link table `purchase_sell_lines` is never written), so
 * reports that need a cost use this: every purchase line of a non-draft purchase, `purchase_rate x
 * quantity x packing_qty` over `quantity x packing_qty` in base units, with what was returned to the
 * supplier taken off both. Purchases dated after the day asked for are not known yet and are left out.
 * A variation that was never purchased has no cost (null), never a guessed zero. The landed cost of the line
 * (freight, duty and so on spread over it, see Services\LandedCost) is added to its cost, in proportion to what
 * was not returned.
 */
class AverageCost
{
    private static ?bool $openingTracked = null;

    private const PACKING = '(case when apl.packing_qty is null or apl.packing_qty < 1 then 1 else apl.packing_qty end)';

    /**
     * A scalar subquery for the average cost of the outer row's variation as of the outer row's date,
     * to be used with `selectSub`. The outer query must expose the given columns.
     */
    public static function correlated(string $variationColumn, string $companyColumn, string $dateColumn): Builder
    {
        return self::base()
            ->whereColumn('apl.variation_id', $variationColumn)
            ->whereColumn('apt.company_id', $companyColumn)
            ->whereColumn('apt.transaction_date', '<=', $dateColumn)
            ->selectRaw(self::average());
    }

    /**
     * Average cost per base unit of every variation of a company on the given day (today when null), keyed
     * by variation id.
     *
     * @return Collection<int, float>
     */
    public static function byVariation(?int $companyId, ?string $asOf = null): Collection
    {
        return self::base()
            ->when($companyId !== null, fn (Builder $query) => $query->where('apt.company_id', $companyId))
            ->when($asOf !== null && $asOf !== '', fn (Builder $query) => $query->whereDate('apt.transaction_date', '<=', $asOf))
            ->groupBy('apl.variation_id')
            ->selectRaw('apl.variation_id, '.self::average().' as average_cost')
            ->get()
            ->filter(fn (object $row): bool => $row->average_cost !== null)
            ->mapWithKeys(fn (object $row): array => [(int) $row->variation_id => (float) $row->average_cost]);
    }

    /**
     * The same rows the average is built from, one per purchase / opening-stock line (a cost layer): variation_id, layer_date,
     * layer_id, qty (base units, net of returns) and cost (that quantity's cost with its landed cost). For FIFO costing
     * (Services\Valuation\LayerCosting); the weighted average of these rows over a date is exactly byVariation().
     */
    public static function layerRows(int $companyId, ?string $from, string $to): Builder
    {
        $packing = self::PACKING;
        $landed = 'coalesce(apl.landed_cost * (apl.quantity - apl.quantity_returned) / nullif(apl.quantity, 0), 0)';

        if (self::openingCostsAreTracked()) {
            $purchase = "apt.type = '".Transaction::TYPE_PURCHASE."'";
            $quantity = "(case when {$purchase} then (apl.quantity - apl.quantity_returned) else apl.quantity_adjustment end) * {$packing}";
            $cost = "(case when {$purchase} then apl.purchase_rate * (apl.quantity - apl.quantity_returned) * {$packing} + {$landed} else apl.opening_cost * apl.quantity_adjustment * {$packing} end)";
        } else {
            $quantity = "(apl.quantity - apl.quantity_returned) * {$packing}";
            $cost = "(apl.purchase_rate * (apl.quantity - apl.quantity_returned) * {$packing} + {$landed})";
        }

        return self::base()
            ->where('apt.company_id', $companyId)
            ->when($from !== null, fn (Builder $query) => $query->whereDate('apt.transaction_date', '>=', $from))
            ->whereDate('apt.transaction_date', '<=', $to)
            ->selectRaw("apl.variation_id, apt.transaction_date as layer_date, apl.id as layer_id, {$quantity} as qty, {$cost} as cost")
            ->orderBy('apt.transaction_date')
            ->orderBy('apl.id');
    }

    private static function base(): Builder
    {
        return DB::table('purchase_lines as apl')
            ->join('transactions as apt', 'apt.id', '=', 'apl.transaction_id')
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $purchase) => $purchase->where('apt.type', Transaction::TYPE_PURCHASE)->where('apt.status', '!=', 'draft'));

                if (self::openingCostsAreTracked()) {
                    $query->orWhere(fn (Builder $opening) => $opening->where('apt.type', Transaction::TYPE_ADJUSTMENT)
                        ->where('apt.status', 'completed')
                        ->whereNotNull('apl.opening_cost')
                        ->where('apl.quantity_adjustment', '>', 0));
                }
            })
            ->whereNull('apt.deleted_at');
    }

    /**
     * An Opening Stock screen entry carries its unit cost on the line (`opening_cost`), and counts here as a purchase of
     * that quantity at that cost. No other adjustment has one, so a database where the screen was never used averages
     * exactly the purchases it always did.
     */
    private static function openingCostsAreTracked(): bool
    {
        return self::$openingTracked ??= Schema::hasColumn('purchase_lines', 'opening_cost');
    }

    private static function average(): string
    {
        $packing = self::PACKING;

        $landed = 'coalesce(apl.landed_cost * (apl.quantity - apl.quantity_returned) / nullif(apl.quantity, 0), 0)';

        if (self::openingCostsAreTracked()) {
            $purchase = "apt.type = '".Transaction::TYPE_PURCHASE."'";
            $quantity = "(case when {$purchase} then (apl.quantity - apl.quantity_returned) else apl.quantity_adjustment end)";
            $rate = "(case when {$purchase} then apl.purchase_rate else apl.opening_cost end)";
            $landedPart = "(case when {$purchase} then {$landed} else 0 end)";

            return "sum({$rate} * {$quantity} * {$packing} + {$landedPart}) / nullif(sum({$quantity} * {$packing}), 0)";
        }

        return "sum(apl.purchase_rate * (apl.quantity - apl.quantity_returned) * {$packing} + {$landed}) / nullif(sum((apl.quantity - apl.quantity_returned) * {$packing}), 0)";
    }
}
