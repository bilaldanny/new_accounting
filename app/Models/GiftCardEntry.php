<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a gift card's ledger. `amount` is always positive; the type says which way it moved the
 * balance (issue and topup add, redeem subtracts) and `balance_after` is the card balance once it is applied.
 */
class GiftCardEntry extends Model
{
    public const TYPE_ISSUE = 'issue';

    public const TYPE_REDEEM = 'redeem';

    public const TYPE_TOPUP = 'topup';

    protected $fillable = [
        'gift_card_id',
        'type',
        'amount',
        'balance_after',
        'transaction_id',
        'user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<GiftCard, $this>
     */
    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
