<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<State>
 */
class StateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->state(),
            'country_id' => Country::factory(),
            'country_code' => 'US',
            'iso2' => strtoupper(fake()->lexify('??')),
            'type' => 'province',
            'flag' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (State $state): void {
            $country = $state->country ?? Country::query()->find($state->country_id);

            if ($country !== null) {
                $state->country_code = State::resolveCountryCode($country);
            }
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'flag' => false,
        ]);
    }
}
