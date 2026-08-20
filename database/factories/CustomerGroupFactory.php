<?php

namespace Database\Factories;

use App\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerGroup>
 */
class CustomerGroupFactory extends Factory
{
    protected $model = CustomerGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'price_calculation_type' => 'percentage',
            'calculation_percentage' => fake()->randomFloat(2, -20, 20),
            'active' => 1,
        ];
    }
}
