<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductDiscountRule;
use Illuminate\Support\Facades\Schema;

/**
 * Standing per-product / per-category / per-brand discounts. The sale and POS forms get the rules that apply to each
 * product with its search row and prefill the line discount from the best one for the quantity on the line; the price
 * limits (PriceRules) still decide whether the resulting price may be sold.
 *
 * When two rules match, the narrower scope wins (product, then category, then brand, then all), then the higher
 * priority, then the larger discount.
 */
class ProductDiscountRules
{
    private const SCOPE_RANK = ['product' => 0, 'category' => 1, 'brand' => 2, 'all' => 3];

    private static ?bool $supported = null;

    public static function supported(): bool
    {
        return self::$supported ??= Schema::hasTable('product_discount_rules');
    }

    /**
     * The active rules in force on a day that can apply to this product, narrowest scope first.
     *
     * @return list<array{id: int, name: string, scope: string, discount_type: string, value: float, min_quantity: float|null, ends_on: string|null}>
     */
    public function rulesFor(int $companyId, Product $product, ?string $onDate = null): array
    {
        if (! self::supported()) {
            return [];
        }

        $day = $onDate ?? now()->toDateString();

        $rules = ProductDiscountRule::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $day))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $day))
            ->where(function ($q) use ($product): void {
                $q->where('scope', 'all')
                    ->orWhere(fn ($sub) => $sub->where('scope', 'product')->where('product_id', $product->id))
                    ->orWhere(fn ($sub) => $sub->where('scope', 'category')->whereIn('category_id', array_filter([(int) $product->category_id, (int) $product->subcategory_id])))
                    ->orWhere(fn ($sub) => $sub->where('scope', 'brand')->where('brand_id', (int) $product->brand_id));
            })
            ->get();

        return $rules
            ->sort(fn (ProductDiscountRule $a, ProductDiscountRule $b): int => [self::SCOPE_RANK[$a->scope], -$a->priority, -$a->value] <=> [self::SCOPE_RANK[$b->scope], -$b->priority, -$b->value])
            ->map(fn (ProductDiscountRule $rule): array => [
                'id' => (int) $rule->id,
                'name' => (string) $rule->name,
                'scope' => (string) $rule->scope,
                'discount_type' => (string) $rule->discount_type,
                'value' => (float) $rule->value,
                'min_quantity' => $rule->min_quantity,
                'ends_on' => $rule->ends_on?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * The one rule that applies to a quantity of a product, or null.
     *
     * @return array{id: int, name: string, scope: string, discount_type: string, value: float, min_quantity: float|null, ends_on: string|null}|null
     */
    public function bestFor(int $companyId, Product $product, float $quantity, ?string $onDate = null): ?array
    {
        foreach ($this->rulesFor($companyId, $product, $onDate) as $rule) {
            if ($rule['min_quantity'] === null || $quantity >= (float) $rule['min_quantity']) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * What a rule takes off a unit price, never more than the price itself.
     */
    public static function amountOff(array $rule, float $unitPrice): float
    {
        $amount = $rule['discount_type'] === 'percent' ? $unitPrice * $rule['value'] / 100 : $rule['value'];

        return round(min(max($amount, 0), $unitPrice), 4);
    }
}
