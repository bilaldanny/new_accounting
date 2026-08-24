<?php

namespace Database\Factories;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => Transaction::TYPE_PURCHASE,
            'status' => 'pending',
            'payment_status' => 'due',
            'discount_type' => 'none',
            'pay_type' => 'day',
            'total_item' => 0,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'final_amount' => 0,
            'transaction_date' => now(),
        ];
    }
}
