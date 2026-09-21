<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund owed for a gift card payment whose card is gone (see App\Services\SaleGiftCards). Signed: a
 * positive row is money owed to the customer, a negative one a restored sale taking it back. Settled by hand.
 */
class GiftCardOrphanRefund extends Model
{
    protected $fillable = [
        'company_id',
        'transaction_id',
        'gift_card_code',
        'amount',
        'reason',
        'created_by',
        'resolved_at',
        'resolved_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
