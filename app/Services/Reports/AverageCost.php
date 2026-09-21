<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The weighted average purchase cost of a product variation per base unit.
 *
 * The app does not keep a cost per sale (the FIFO link table `purchase_sell_lines` is never written), so
 * reports that need a cost use this: every purchase line of a non-draft purchase, `purchase_rate x
 * quantity x packing_qty` over `quantity x packing_qty` in base units, with what was returned to the
 * supplier taken off both. Purchases dated after the day asked for are not known yet and are left out.
 * A variation that was never purchased has no cost (null), never a guessed zero.
 */
class AverageCost
{
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

    private static function base(): Builder
    {
        return DB::table('purchase_lines as apl')
            ->join('transactions as apt', 'apt.id', '=', 'apl.transaction_id')
            ->where('apt.type', Transaction::TYPE_PURCHASE)
            ->where('apt.status', '!=', 'draft')
            ->whereNull('apt.deleted_at');
    }

    private static function average(): string
    {
        $packing = self::PACKING;

        return "sum(apl.purchase_rate * (apl.quantity - apl.quantity_returned) * {$packing}) / nullif(sum((apl.quantity - apl.quantity_returned) * {$packing}), 0)";
    }
}
