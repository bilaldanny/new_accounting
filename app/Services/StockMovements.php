<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Unit;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The single definition of stock. Everything that shows or checks stock (PurchaseLine::currentStock,
 * the per-unit quantities on the sell/purchase/transfer/adjustment forms, the transfer and
 * adjustment guards and the Low Stock report) reads it from here.
 *
 * Stock is derived, never stored, and always expressed in the product's base unit: every line counts
 * as `quantity x packing_qty` (the line's own base-units-per-unit factor, 1 when missing).
 *
 *   + purchase lines:            received - sold - returned - adjustment  (purchase orders)
 *   - completed transfers out, + completed transfers in, +/- completed adjustments
 *   - sales:                     quantity - quantity_returned  (sell lines)
 *
 * A sale counts from the moment it is saved (no need to wait for an issue note) unless it is a draft
 * or quotation, and a sale return simply gives its quantity back. Only sales created at or after
 * `settings.stock_sales_cutover_at` count, so existing history is not deducted retroactively; a NULL
 * cutover means no cutoff. A return of a sale that predates the cutover is ignored with that sale.
 *
 * `purchase_lines.qunatity_sold` (sic) is still part of the purchase term but is never written by
 * the app. It must stay unused while sales are deducted from sell lines, or they would count twice.
 */
class StockMovements
{
    private static ?bool $salesTracked = null;

    /**
     * One row per movement line: product_id, variation_id, branch_id, qty (base units, signed).
     * `as_of` (a `Y-m-d` day) keeps only movements whose document is dated on or before that day.
     *
     * @param  array{product_id?: int|null, variation_id?: int|null, branch_id?: int|null, exclude_sale_id?: int|null, as_of?: string|null}  $filters
     */
    public static function query(array $filters = []): Builder
    {
        $productId = $filters['product_id'] ?? null;
        $variationId = $filters['variation_id'] ?? null;
        $branchId = $filters['branch_id'] ?? null;
        $excludeSaleId = $filters['exclude_sale_id'] ?? null;
        $asOf = $filters['as_of'] ?? null;

        $scope = fn (Builder $query, string $line, string $branchColumn): Builder => $query
            ->when($productId !== null, fn (Builder $q) => $q->where("{$line}.product_id", $productId))
            ->when($variationId !== null, fn (Builder $q) => $q->where("{$line}.variation_id", $variationId))
            ->when($branchId !== null, fn (Builder $q) => $q->where($branchColumn, $branchId))
            ->when($asOf !== null, fn (Builder $q) => $q->whereDate('t.transaction_date', '<=', $asOf));

        $lines = fn (string $type, bool $completedOnly): Builder => DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->whereNull('t.deleted_at')
            ->where('t.type', $type)
            ->when($completedOnly, fn (Builder $query) => $query->where('t.status', 'completed'));

        $purchased = $scope($lines(Transaction::TYPE_PURCHASE, false), 'pl', 't.branch_id')
            ->select('pl.product_id', 'pl.variation_id', 't.branch_id as branch_id', DB::raw('(pl.quantity_received - pl.qunatity_sold - pl.quantity_returned - pl.quantity_adjustment) * (case when pl.packing_qty is null or pl.packing_qty < 1 then 1 else pl.packing_qty end) as qty'));

        $transferredOut = $scope($lines(Transaction::TYPE_TRANSFER, true), 'pl', 't.branch_id')
            ->select('pl.product_id', 'pl.variation_id', 't.branch_id as branch_id', DB::raw('-pl.quantity * (case when pl.packing_qty is null or pl.packing_qty < 1 then 1 else pl.packing_qty end) as qty'));

        $transferredIn = $scope($lines(Transaction::TYPE_TRANSFER, true), 'pl', 't.tobranch_id')
            ->select('pl.product_id', 'pl.variation_id', 't.tobranch_id as branch_id', DB::raw('pl.quantity * (case when pl.packing_qty is null or pl.packing_qty < 1 then 1 else pl.packing_qty end) as qty'));

        $adjusted = $scope($lines(Transaction::TYPE_ADJUSTMENT, true), 'pl', 't.branch_id')
            ->select('pl.product_id', 'pl.variation_id', 't.branch_id as branch_id', DB::raw('pl.quantity_adjustment * (case when pl.packing_qty is null or pl.packing_qty < 1 then 1 else pl.packing_qty end) as qty'));

        $movements = $purchased
            ->unionAll($transferredOut)
            ->unionAll($transferredIn)
            ->unionAll($adjusted);

        if (self::salesAreTracked()) {
            $sold = $scope(DB::table('sell_lines as sl')->join('transactions as t', 't.id', '=', 'sl.transaction_id'), 'sl', 't.branch_id')
                ->whereNull('t.deleted_at')
                ->where('t.type', Transaction::TYPE_SELL)
                ->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)
                ->where(function (Builder $query): void {
                    $query->whereRaw('(select stock_sales_cutover_at from settings limit 1) is null')
                        ->orWhereRaw('t.created_at >= (select stock_sales_cutover_at from settings limit 1)');
                })
                ->when($excludeSaleId !== null, fn (Builder $query) => $query->where('t.id', '!=', $excludeSaleId))
                ->select('sl.product_id', 'sl.variation_id', 't.branch_id as branch_id', DB::raw('-(sl.quantity - sl.quantity_returned) * (case when sl.packing_qty is null or sl.packing_qty < 1 then 1 else sl.packing_qty end) as qty'));

            $movements->unionAll($sold);
        }

        return $movements;
    }

    /**
     * Stock in base units for one variation. A null branch is the total across every branch.
     */
    public static function baseStock(int $productId, int $variationId, ?int $branchId = null, ?int $excludeSaleId = null): float
    {
        $total = DB::query()
            ->fromSub(self::query([
                'product_id' => $productId,
                'variation_id' => $variationId,
                'branch_id' => $branchId,
                'exclude_sale_id' => $excludeSaleId,
            ]), 'm')
            ->sum('m.qty');

        return round((float) $total, 6);
    }

    /**
     * The same stock expressed in the given unit (the base unit, or one of its child units).
     */
    public static function inUnit(float $baseStock, int $productId, int $variationId, int $unitId): float
    {
        return self::convert($baseStock, self::unitFactor($productId, $variationId, $unitId));
    }

    /**
     * Whole units of a larger unit that fit in the base stock; the base unit itself is exact.
     */
    public static function convert(float $baseStock, float $factor): float
    {
        if ($factor <= 1) {
            return $baseStock;
        }

        return floor(round($baseStock / $factor, 6));
    }

    /**
     * Base units in one unit of the product (1 for the base unit or any unit that is not its child),
     * the same factor Transaction::unitsForProduct hands to the forms as packing_qty.
     */
    public static function unitFactor(int $productId, int $variationId, int $unitId): float
    {
        $productUnitId = DB::table('products')->where('id', $productId)->value('unit_id');

        if ($productUnitId === null || (int) $productUnitId === $unitId) {
            return 1.0;
        }

        $unit = DB::table('units')->where('id', $unitId)->first(['parent_id', 'type']);

        if ($unit === null || (int) $unit->parent_id !== (int) $productUnitId) {
            return 1.0;
        }

        $detail = DB::table('product_details')->where('id', $variationId)->first(['smallquantity', 'largequantity']);

        return match ($unit->type) {
            'small' => (float) max((int) ($detail->smallquantity ?? 0), 1),
            'large' => (float) max((int) ($detail->largequantity ?? 0), 1),
            default => 1.0,
        };
    }

    /**
     * The per-sale rule the SQL above applies, for checks made before a sale exists.
     */
    public static function saleCountsAgainstStock(?string $status, ?CarbonInterface $createdAt = null): bool
    {
        if (! self::salesAreTracked() || in_array($status, Transaction::UNPOSTED_SELL_STATUSES, true)) {
            return false;
        }

        $cutover = self::salesCutover();

        return $cutover === null || ($createdAt ?? now())->greaterThanOrEqualTo($cutover);
    }

    public static function salesCutover(): ?CarbonInterface
    {
        if (! self::salesAreTracked()) {
            return null;
        }

        $value = Setting::query()->value('stock_sales_cutover_at');

        return $value === null ? null : Carbon::parse($value);
    }

    /**
     * Forget whether the cutover column exists (the migration calls this, so a migrate in the same
     * process is picked up).
     */
    public static function forgetSchemaMemo(): void
    {
        self::$salesTracked = null;
    }

    /**
     * False until the cutover column exists, so a deploy that has not run its migration yet keeps
     * the previous behaviour instead of failing every stock query.
     */
    private static function salesAreTracked(): bool
    {
        return self::$salesTracked ??= Schema::hasColumn('settings', 'stock_sales_cutover_at');
    }
}
