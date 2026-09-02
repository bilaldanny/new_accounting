<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'state_id' => State::factory(),
            'state_code' => 'CA',
            'country_id' => fn (array $attributes): int => State::query()->findOrFail($attributes['state_id'])->country_id,
            'country_code' => 'US',
            'latitude' => 0,
            'longitude' => 0,
            'flag' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (City $city): void {
            $state = $city->state ?? State::query()->find($city->state_id);
            $country = $state?->country ?? Country::query()->find($city->country_id ?? $state?->country_id);

            if ($state !== null && $country !== null) {
                $city->country_id = $country->id;
                $city->country_code = State::resolveCountryCode($country);
                $city->state_code = City::resolveStateCode($state, $country);
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
