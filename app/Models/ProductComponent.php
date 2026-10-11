<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a product component list. For a product whose item type is a kit it says what a sale takes out of
 * stock; for an inventory product it is the bill of materials the kitting / de-kitting screen works from.
 */
class ProductComponent extends Model
{
    protected $fillable = ['company_id', 'product_id', 'component_product_id', 'component_variation_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
