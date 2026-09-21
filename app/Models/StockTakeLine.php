<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product of a count sheet. `system_qty` is the stock (in the product's base unit) frozen when the
 * sheet was opened; `counted_qty` is what was found on the shelf, null until someone counts it.
 */
class StockTakeLine extends Model
{
    protected $fillable = [
        'stock_take_id',
        'product_id',
        'variation_id',
        'unit_id',
        'system_qty',
        'counted_qty',
    ];

    protected function casts(): array
    {
        return [
            'system_qty' => 'float',
            'counted_qty' => 'float',
        ];
    }

    /**
     * @return BelongsTo<StockTake, $this>
     */
    public function stockTake(): BelongsTo
    {
        return $this->belongsTo(StockTake::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductDetail, $this>
     */
    public function productdetail(): BelongsTo
    {
        return $this->belongsTo(ProductDetail::class, 'variation_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Counted minus system, to 2 decimals (the precision a stock adjustment line keeps); null while the
     * line is uncounted.
     */
    public function difference(): ?float
    {
        return $this->counted_qty === null ? null : round($this->counted_qty - $this->system_qty, 2);
    }
}
