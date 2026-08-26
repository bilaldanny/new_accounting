<?php

namespace Database\Factories;

use App\Models\SellLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SellLine>
 */
class SellLineFactory extends Factory
{
    protected $model = SellLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quantity' => 1,
            'quantity_issue' => 0,
            'quantity_returned' => 0,
            'unit_price' => 0,
            'discount_percent' => 0,
            'unit_price_after_discount' => 0,
            'subtotal' => 0,
            'packing_qty' => 1,
        ];
    }
}
