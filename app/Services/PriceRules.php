<?php

namespace App\Services;

use App\Models\ProductDetail;
use Illuminate\Validation\ValidationException;

/**
 * Minimum / Maximum Selling Price Rules: a sale line may not be sold below the variation's `min_sell_price` or
 * above its `max_sell_price` (per selling unit, as entered on the line after its discount). A variation with no
 * limit is free. A user holding the `/sell/price-override` permission may go outside the limits.
 */
class PriceRules
{
    /**
     * @param  array<int, mixed>  $lines  the sale lines of the request
     *
     * @throws ValidationException
     */
    public function assertSellLines(array $lines): void
    {
        if (hasMenuPermission('/sell/price-override')) {
            return;
        }

        $limits = ProductDetail::query()
            ->whereIn('id', collect($lines)->pluck('variation_id')->filter()->unique())
            ->where(fn ($q) => $q->whereNotNull('min_sell_price')->orWhereNotNull('max_sell_price'))
            ->get(['id', 'name', 'min_sell_price', 'max_sell_price'])
            ->keyBy('id');

        if ($limits->isEmpty()) {
            return;
        }

        $errors = [];

        foreach ($lines as $index => $line) {
            $detail = is_array($line) ? $limits->get((int) ($line['variation_id'] ?? 0)) : null;

            if ($detail === null) {
                continue;
            }

            $price = (float) ($line['unit_price_after_discount'] ?? $line['unit_price'] ?? 0);

            if ($detail->min_sell_price !== null && $price < (float) $detail->min_sell_price) {
                $errors["selllines.{$index}.unit_price"] = ["{$detail->name} cannot be sold below its minimum price of ".number_format((float) $detail->min_sell_price, 2).'.'];
            } elseif ($detail->max_sell_price !== null && $price > (float) $detail->max_sell_price) {
                $errors["selllines.{$index}.unit_price"] = ["{$detail->name} cannot be sold above its maximum price of ".number_format((float) $detail->max_sell_price, 2).'.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
