<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One extra cost of a purchase (freight, customs duty, insurance, clearing, other), spread over the purchase's
 * lines by value or by quantity (see App\Services\LandedCost).
 */
class PurchaseLandedCost extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    public const TYPES = ['freight', 'customs_duty', 'insurance', 'clearing', 'other'];

    /**
     * @var list<string>
     */
    public const BASES = ['value', 'quantity'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
