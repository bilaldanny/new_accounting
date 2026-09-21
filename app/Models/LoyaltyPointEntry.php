<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a customer's points ledger. `points` is signed (earn adds, redeem subtracts, adjust either
 * way) and `balance_after` is the customer's balance once it is applied. `amount` is the sale amount that
 * earned the points, or the currency value of a redemption.
 */
class LoyaltyPointEntry extends Model
{
    public const TYPE_EARN = 'earn';

    public const TYPE_REDEEM = 'redeem';

    public const TYPE_ADJUST = 'adjust';

    protected $fillable = [
        'company_id',
        'contact_id',
        'type',
        'points',
        'balance_after',
        'amount',
        'transaction_id',
        'user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'balance_after' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
