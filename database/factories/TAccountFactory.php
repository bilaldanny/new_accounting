<?php

namespace Database\Factories;

use App\Models\TAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TAccount>
 */
class TAccountFactory extends Factory
{
    protected $model = TAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_code' => '000-00000',
            'voucher_no' => 'PE-00001',
            'ref_no' => 'PO-00001',
            'cheque_no' => '',
            'comments' => '',
            'status' => 'approved',
            'type' => 'online',
            'total_amount' => 0,
            'total_tax' => 0,
            'net_total' => 0,
            'voucher_date' => now(),
        ];
    }
}
