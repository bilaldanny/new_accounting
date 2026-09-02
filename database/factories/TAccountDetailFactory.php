<?php

namespace Database\Factories;

use App\Models\TAccount;
use App\Models\TAccountDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TAccountDetail>
 */
class TAccountDetailFactory extends Factory
{
    protected $model = TAccountDetail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            't_account_id' => TAccount::factory(),
            'account_code' => '000-00000',
            'description' => 'Journal line',
            'acc_nature' => 'dr',
            'debit' => 0,
            'credit' => 0,
            'amount' => 0,
            'highlight' => false,
        ];
    }
}
