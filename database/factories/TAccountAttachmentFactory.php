<?php

namespace Database\Factories;

use App\Models\TAccount;
use App\Models\TAccountAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TAccountAttachment>
 */
class TAccountAttachmentFactory extends Factory
{
    protected $model = TAccountAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            't_account_id' => TAccount::factory(),
            'file_name' => 'voucher.pdf',
            'file_url' => null,
            'ext' => 'pdf',
        ];
    }
}
