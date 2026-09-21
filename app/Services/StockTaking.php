<?php

namespace App\Services;

use App\Models\ProductDetail;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The life of a stock take (physical count):
 *
 * 1. `open()` freezes the system stock of a branch (StockMovements, in each product's base unit) into a
 *    draft sheet, one line per product variation that matches the filters.
 * 2. `saveCounts()` records what was counted; a line can be left uncounted, or corrected again, while the
 *    sheet is a draft.
 * 3. `complete()` turns every counted line that differs from its frozen system quantity into one line of
 *    a normal, completed stock adjustment (`counted - system`, so a shortage is a decrease) and closes
 *    the sheet. Uncounted lines are left alone.
 *
 * The count is compared with the quantity frozen when the sheet was opened, not with today's stock, on
 * purpose: the count describes the shelf at that moment, and any sale since is its own movement that
 * already counts. Counting is in each product's base unit only.
 *
 * A refused step throws a RuntimeException whose message is the reason: `not_draft`, `nothing_counted`.
 * A decrease the branch cannot cover is refused by the stock adjustment itself with a ValidationException.
 */
class StockTaking
{
    /**
     * Most products one sheet can hold; narrow the filters (category, item type, brand) beyond this.
     */
    public const MAX_LINES = 1000;

    /**
     * Opens a draft sheet for a branch.
     *
     * @param  array{category_id?: mixed, subcategory_id?: mixed, itemtype_id?: mixed, brand_id?: mixed, only_in_stock?: mixed}  $filters
     *
     * @throws ValidationException when no product matches or too many do
     */
    public function open(int $companyId, int $branchId, string $countDate, ?string $note, array $filters = [], ?int $userId = null): StockTake
    {
        $stock = $this->systemStock($branchId);
        $onlyInStock = filter_var($filters['only_in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $details = $this->variations($companyId, $filters)
            ->filter(fn (ProductDetail $detail): bool => ! $onlyInStock || ($stock[$detail->id] ?? 0) > 0)
            ->values();

        if ($details->isEmpty()) {
            throw ValidationException::withMessages(['filters' => ['No products match this count.']]);
        }

        if ($details->count() > self::MAX_LINES) {
            throw ValidationException::withMessages(['filters' => ['Too many products for one count ('.$details->count().'). Narrow it by category, item type or brand.']]);
        }

        return DB::transaction(function () use ($companyId, $branchId, $countDate, $note, $userId, $details, $stock): StockTake {
            $take = StockTake::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'reference' => $this->nextReference($companyId),
                'count_date' => $countDate,
                'status' => StockTake::STATUS_DRAFT,
                'note' => $note,
                'created_by' => $userId,
            ]);

            $rows = $details->map(fn (ProductDetail $detail): array => [
                'stock_take_id' => $take->id,
                'product_id' => $detail->product_id,
                'variation_id' => $detail->id,
                'unit_id' => $detail->product?->unit_id,
                'system_qty' => round((float) ($stock[$detail->id] ?? 0), 4),
                'counted_qty' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            foreach (array_chunk($rows, 200) as $chunk) {
                StockTakeLine::query()->insert($chunk);
            }

            return $take;
        });
    }

    /**
     * Records counted quantities (`line id => quantity`, null clears a count) on a draft sheet. Ids that
     * are not lines of this sheet are ignored.
     *
     * @param  array<int|string, float|int|string|null>  $counts
     */
    public function saveCounts(StockTake $take, array $counts): void
    {
        DB::transaction(function () use ($take, $counts): void {
            $locked = StockTake::query()->lockForUpdate()->findOrFail($take->id);

            if (! $locked->isDraft()) {
                throw new RuntimeException('not_draft');
            }

            $lines = $locked->lines()->whereIn('id', array_keys($counts))->get()->keyBy('id');

            foreach ($counts as $lineId => $quantity) {
                $line = $lines->get((int) $lineId);

                if ($line === null) {
                    continue;
                }

                $line->counted_qty = $quantity === null || $quantity === '' ? null : round((float) $quantity, 4);
                $line->save();
            }
        });
    }

    /**
     * Closes the sheet, writing the stock adjustment for the differences (none when every counted line
     * matches). The adjustment is created by the signed-in user. Returns the sheet with its `adjustment_id` set.
     *
     * @throws ValidationException when the adjustment cannot be applied (a decrease the branch cannot cover)
     */
    public function complete(StockTake $take): StockTake
    {
        return DB::transaction(function () use ($take): StockTake {
            $locked = StockTake::query()->lockForUpdate()->findOrFail($take->id);

            if (! $locked->isDraft()) {
                throw new RuntimeException('not_draft');
            }

            $counted = $locked->lines()->with('product:id,itemtype_id')->whereNotNull('counted_qty')->get();

            if ($counted->isEmpty()) {
                throw new RuntimeException('nothing_counted');
            }

            $differing = $counted->filter(fn (StockTakeLine $line): bool => abs($line->difference() ?? 0.0) >= 0.01)->values();

            if ($differing->isNotEmpty()) {
                $adjustment = Transaction::createAdjustment(new Request([
                    'company_id' => $locked->company_id,
                    'branch_id' => $locked->branch_id,
                    'transaction_date' => $locked->count_date->toDateString(),
                    'adjustment_type' => 'normal',
                    'additional_note' => 'Stock take '.$locked->reference,
                    'status' => 'completed',
                    'total_item' => $differing->count(),
                    'purchaselines' => $differing->map(fn (StockTakeLine $line): array => [
                        'product_id' => $line->product_id,
                        'variation_id' => $line->variation_id,
                        'itemtype_id' => $line->product?->itemtype_id,
                        'unit_id' => $line->unit_id,
                        'quantity_adjustment' => $line->difference(),
                    ])->all(),
                ]));

                $locked->adjustment_id = $adjustment->id;
            }

            $locked->status = StockTake::STATUS_COMPLETED;
            $locked->completed_at = now();
            $locked->save();

            return $locked;
        });
    }

    /**
     * `ST-00001`-style reference, unique within the company (trashed sheets included).
     */
    public function nextReference(int $companyId): string
    {
        $next = (int) StockTake::withTrashed()->where('company_id', $companyId)->max('id') + 1;

        do {
            $reference = 'ST-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (StockTake::withTrashed()->where('company_id', $companyId)->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * Current stock per variation for one branch, in base units.
     *
     * @return array<int, float>
     */
    private function systemStock(int $branchId): array
    {
        return DB::query()
            ->fromSub(StockMovements::query(['branch_id' => $branchId]), 'm')
            ->selectRaw('m.variation_id, SUM(m.qty) as qty')
            ->groupBy('m.variation_id')
            ->pluck('qty', 'variation_id')
            ->map(fn (mixed $qty): float => (float) $qty)
            ->all();
    }

    /**
     * The active product variations of the company that match the filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ProductDetail>
     */
    private function variations(int $companyId, array $filters): Collection
    {
        $only = fn (string $key): ?int => filled($filters[$key] ?? null) ? (int) $filters[$key] : null;
        $categoryId = $only('category_id');
        $subcategoryId = $only('subcategory_id');
        $itemTypeId = $only('itemtype_id');
        $brandId = $only('brand_id');

        return ProductDetail::query()
            ->with('product:id,unit_id,itemtype_id')
            ->whereHas('product', function (Builder $query) use ($companyId, $categoryId, $subcategoryId, $itemTypeId, $brandId): void {
                $query->where('company_id', $companyId)
                    ->where('active', 1)
                    ->when($categoryId !== null, fn (Builder $q) => $q->where('category_id', $categoryId))
                    ->when($subcategoryId !== null, fn (Builder $q) => $q->where('subcategory_id', $subcategoryId))
                    ->when($itemTypeId !== null, fn (Builder $q) => $q->where('itemtype_id', $itemTypeId))
                    ->when($brandId !== null, fn (Builder $q) => $q->where('brand_id', $brandId));
            })
            ->orderBy('product_id')
            ->orderBy('id')
            ->get();
    }
}
