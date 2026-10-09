<?php

namespace App\Services;

use App\Models\PriceList;
use App\Models\PriceListDetail;
use App\Models\Product;
use App\Models\ProductDetail;
use Carbon\CarbonInterface;

/**
 * The selling price of a product variation for a customer and a quantity (Products & Catalog Phase 2).
 *
 * Looked up in this order, the first hit wins:
 *  1. an approved price list made for this customer (contact-specific, "contract pricing");
 *  2. an approved price list of the product's brand (no customer),
 *  3. the variation's own default sell price.
 * A list counts when its effective date is on or before the day and (when a branch is given) it is the
 * branch's. Among lists of the same kind the one with the latest effective date wins. Inside a list, a line for
 * the exact variation beats a line for the product as a whole; the quantity break with the highest
 * `min_qty` not above the quantity sets the price, else the line's own price. The line's discount % is returned
 * for the caller to apply; the list's overall discount is not applied here.
 */
class PriceResolver
{
    /**
     * @return array{price: float, discount_percent: float, source: string, price_list_id: ?int, tier_min_qty: ?float, default_price: float}
     */
    public function resolve(int $companyId, ?int $branchId, ?int $contactId, int $productId, ?int $variationId, float $quantity = 1, ?CarbonInterface $on = null): array
    {
        $product = Product::query()->findOrFail($productId);
        $variation = $variationId !== null
            ? ProductDetail::query()->where('product_id', $productId)->find($variationId)
            : ProductDetail::query()->where('product_id', $productId)->orderBy('id')->first();
        $default = round((float) ($variation?->default_sell_price ?? 0), 2);
        $day = ($on ?? now())->toDateString();

        $lists = PriceList::query()
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->whereDate('date', '<=', $day)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where(function ($q) use ($contactId, $product): void {
                if ($contactId !== null) {
                    $q->where('contact_id', $contactId);
                }

                $q->orWhere(function ($brand) use ($product): void {
                    $brand->whereNull('contact_id')->where('brand_id', $product->brand_id);
                });
            })
            ->orderByRaw('CASE WHEN contact_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        foreach ($lists as $list) {
            $detail = $this->detailFor($list, $productId, $variation?->id);

            if ($detail === null) {
                continue;
            }

            $tier = $detail->tiers->where('min_qty', '<=', $quantity)->sortByDesc('min_qty')->first();

            return [
                'price' => round((float) ($tier?->sell_price ?? $detail->sell_price), 2),
                'discount_percent' => round((float) $detail->discount, 2),
                'source' => $list->contact_id !== null ? 'contact_price_list' : 'brand_price_list',
                'price_list_id' => (int) $list->id,
                'tier_min_qty' => $tier?->min_qty,
                'default_price' => $default,
            ];
        }

        return ['price' => $default, 'discount_percent' => 0.0, 'source' => 'default', 'price_list_id' => null, 'tier_min_qty' => null, 'default_price' => $default];
    }

    private function detailFor(PriceList $list, int $productId, ?int $variationId): ?PriceListDetail
    {
        $details = $list->pricelistdetails()->with('tiers')->where('product_id', $productId)->get();

        return $details->firstWhere('variation_id', $variationId) ?? $details->whereNull('variation_id')->first();
    }
}
