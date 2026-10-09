<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductDetail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bulk Price Update: change one price field of many product variations at once, as a percentage, a fixed
 * amount or a set value, with a preview before anything is saved.
 *
 * Fields: `default_sell_price`, `default_purchase_price` (its unit price `dpp_unit_price` follows in the same
 * proportion), `min_sell_price` and `max_sell_price`. Modes: `percent` (adjust the current price by +/- a
 * percentage), `fixed` (add or take off an amount), `set` (set the price), and, for the minimum and maximum
 * only, `percent_of_sell` (that percentage of the variation's current sell price). A price never goes below
 * zero. A limit that is not set cannot be adjusted by `percent` or `fixed`: that row is skipped. When a sell or
 * purchase price changes, the variation's profit percent is recalculated. Saves go through the model, so every
 * change is in the Activity Log and the Price & Cost History report.
 */
class BulkPriceUpdate
{
    public const FIELDS = ['default_sell_price', 'default_purchase_price', 'min_sell_price', 'max_sell_price'];

    public const MODES = ['percent', 'fixed', 'set', 'percent_of_sell'];

    public const MAX_ROWS = 5000;

    /**
     * @param  array{company_id?: ?int, field: string, mode: string, value: float|int|string, category_id?: ?int, brand_id?: ?int, product_ids?: list<int>, search?: ?string, round_to?: float|int|string|null}  $request
     * @return Collection<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function preview(array $request): Collection
    {
        $field = $request['field'];
        $mode = $request['mode'];
        $value = (float) $request['value'];

        if (in_array($mode, ['percent_of_sell'], true) && ! in_array($field, ['min_sell_price', 'max_sell_price'], true)) {
            throw ValidationException::withMessages(['mode' => ['"Percent of sell price" is only for the minimum and maximum price.']]);
        }

        $rows = $this->variations($request)->map(function (ProductDetail $detail) use ($field, $mode, $value, $request): array {
            $old = $detail->{$field} === null ? null : round((float) $detail->{$field}, 2);
            $new = $this->newPrice($old, $mode, $value, (float) $detail->default_sell_price, $request['round_to'] ?? null);

            return [
                'id' => $detail->id,
                'product_name' => $detail->product?->name,
                'variation_name' => $detail->variation_name === 'dummy' ? null : $detail->variation_name,
                'sku' => $detail->sku,
                'old_price' => $old,
                'new_price' => $new,
                'skipped' => $new === null,
            ];
        });

        if ($rows->count() > self::MAX_ROWS) {
            throw ValidationException::withMessages(['filters' => ['That would change more than '.self::MAX_ROWS.' variations at once. Narrow the filter.']]);
        }

        return $rows->values();
    }

    /**
     * Saves what `preview()` shows (skipped rows and rows that would not change are left alone).
     *
     * @param  array<string, mixed>  $request
     * @return int the variations changed
     */
    public function apply(array $request): int
    {
        $field = $request['field'];
        $changes = $this->preview($request)->filter(fn (array $row): bool => ! $row['skipped'] && $row['new_price'] !== $row['old_price'])->keyBy('id');

        return DB::transaction(function () use ($changes, $field): int {
            foreach (ProductDetail::query()->whereIn('id', $changes->keys())->get() as $detail) {
                $new = $changes[$detail->id]['new_price'];

                if ($field === 'default_purchase_price' && (float) $detail->default_purchase_price > 0) {
                    $detail->dpp_unit_price = round((float) $detail->dpp_unit_price * $new / (float) $detail->default_purchase_price, 2);
                }

                $detail->{$field} = $new;

                if (in_array($field, ['default_sell_price', 'default_purchase_price'], true) && (float) $detail->default_purchase_price > 0) {
                    $detail->profit_percent = round(((float) $detail->default_sell_price - (float) $detail->default_purchase_price) / (float) $detail->default_purchase_price * 100, 2);
                }

                $detail->save();
            }

            return $changes->count();
        });
    }

    private function newPrice(?float $old, string $mode, float $value, float $sellPrice, mixed $roundTo): ?float
    {
        $new = match ($mode) {
            'set' => $value,
            'percent_of_sell' => $sellPrice * $value / 100,
            'percent' => $old === null ? null : $old * (1 + $value / 100),
            'fixed' => $old === null ? null : $old + $value,
            default => null,
        };

        if ($new === null) {
            return null;
        }

        $step = is_numeric($roundTo) && (float) $roundTo > 0 ? (float) $roundTo : 0.01;

        return max(round(round($new / $step) * $step, 2), 0.0);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return Collection<int, ProductDetail>
     */
    private function variations(array $request): Collection
    {
        $productIds = Product::query()
            ->when(! empty($request['company_id']), fn ($q) => $q->where('company_id', $request['company_id']))
            ->when(! empty($request['category_id']), fn ($q) => $q->where('category_id', $request['category_id']))
            ->when(! empty($request['brand_id']), fn ($q) => $q->where('brand_id', $request['brand_id']))
            ->when(! empty($request['product_ids']), fn ($q) => $q->whereIn('id', $request['product_ids']))
            ->when(trim((string) ($request['search'] ?? '')) !== '', fn ($q) => $q->where(fn ($s) => $s->where('name', 'like', '%'.trim((string) $request['search']).'%')->orWhere('sku', 'like', '%'.trim((string) $request['search']).'%')))
            ->pluck('id');

        return ProductDetail::query()->whereIn('product_id', $productIds)->with('product:id,name')->orderBy('product_id')->orderBy('id')->get();
    }
}
