<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListDetail extends Model
{
    protected $fillable = [
        'list_id',
        'product_id',
        'variation_id',
        'unit_id',
        'purchase_price',
        'sell_price',
        'profit_margin',
        'discount',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'profit_margin' => 'decimal:2',
            'discount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function pricelist(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'list_id');
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
}
