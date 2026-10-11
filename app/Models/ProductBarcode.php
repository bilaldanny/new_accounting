<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra barcode a product answers to (a carton code, a supplier code, an old label). A barcode is unique inside
 * the company; the SKU of a variation keeps working as its own scan code.
 */
class ProductBarcode extends Model
{
    protected $fillable = ['company_id', 'product_id', 'variation_id', 'barcode', 'label'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
