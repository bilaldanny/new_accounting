<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The loyalty points redeemed on one sale at checkout and the amount they took off (see
 * App\Services\SaleIncentives and LoyaltyPoints::syncRedemptionForSale). The sale's `final_amount` already
 * includes the reduction.
 */
class SaleLoyaltyRedemption extends Model
{
    protected $fillable = [
        'transaction_id',
        'contact_id',
        'points',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
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
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
