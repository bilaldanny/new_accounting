<?php

namespace Database\Factories;

use App\Models\ContactLedgerWatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactLedgerWatch>
 */
class ContactLedgerWatchFactory extends Factory
{
    protected $model = ContactLedgerWatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'last_row_id' => 'txn-'.fake()->unique()->numberBetween(1, 9999),
            'last_voucher_date' => fake()->date(),
            'last_voucher_no' => 'PO-'.fake()->unique()->numerify('#####'),
        ];
    }
}
