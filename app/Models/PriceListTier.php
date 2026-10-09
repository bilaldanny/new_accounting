<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quantity break on a price list line: from `min_qty` units the line sells at `sell_price`.
 */
class PriceListTier extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['min_qty' => 'float', 'sell_price' => 'float'];
    }

    /**
     * @return BelongsTo<PriceListDetail, $this>
     */
    public function detail(): BelongsTo
    {
        return $this->belongsTo(PriceListDetail::class, 'price_list_detail_id');
    }
}
