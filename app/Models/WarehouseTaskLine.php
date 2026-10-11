<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseTaskLine extends Model
{
    protected $fillable = ['warehouse_task_id', 'product_id', 'variation_id', 'quantity', 'quantity_done', 'location_id', 'note'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'quantity_done' => 'float'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
