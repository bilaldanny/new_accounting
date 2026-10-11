<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    protected $fillable = ['sales_order_id', 'product_id', 'variation_id', 'unit_id', 'itemtype_id', 'quantity', 'unit_price', 'discount', 'invoiced_qty'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_price' => 'float', 'discount' => 'float', 'invoiced_qty' => 'float'];
    }

    public function remaining(): float
    {
        return round(max($this->quantity - $this->invoiced_qty, 0), 4);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
