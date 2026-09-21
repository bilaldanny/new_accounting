<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The part of a completed cash collection that paid one invoice, and the sell payment it created.
 */
class CashCollectionAllocation extends Model
{
    protected $fillable = [
        'cash_collection_id',
        'transaction_id',
        'payment_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
        ];
    }

    /**
     * @return BelongsTo<CashCollection, $this>
     */
    public function cashCollection(): BelongsTo
    {
        return $this->belongsTo(CashCollection::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
