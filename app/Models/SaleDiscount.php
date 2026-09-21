<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The discount code applied to one sale, with a snapshot of the rule and the amount it took off (see
 * App\Services\SaleIncentives). The sale's `final_amount` already includes the reduction.
 */
class SaleDiscount extends Model
{
    protected $fillable = [
        'transaction_id',
        'discount_id',
        'code',
        'name',
        'discount_type',
        'value',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'amount' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Discount, $this>
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
}
