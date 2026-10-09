<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash put into (`in`) or taken out of (`out`) a POS drawer that is not a sale: change topped up, a petty
 * cash payment, a bank drop.
 */
class PosCashMovement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    /**
     * @return BelongsTo<PosShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }
}
