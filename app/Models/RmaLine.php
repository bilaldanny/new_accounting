<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RmaLine extends Model
{
    protected $fillable = ['rma_request_id', 'product_id', 'variation_id', 'quantity', 'defect'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
