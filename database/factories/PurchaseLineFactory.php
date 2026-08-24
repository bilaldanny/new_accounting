<?php

namespace Database\Factories;

use App\Models\PurchaseLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseLine>
 */
class PurchaseLineFactory extends Factory
{
    protected $model = PurchaseLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quantity' => 1,
            'quantity_received' => 0,
            'qunatity_sold' => 0,
            'quantity_returned' => 0,
            'quantity_adjustment' => 0,
            'purchase_rate' => 0,
            'default_sell_price' => 0,
            'discount_percent' => 0,
            'margin' => 0,
            'pp_without_discount' => 0,
            'packing_qty' => 1,
        ];
    }
}
