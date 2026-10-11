<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A standing discount on one product, a category, a brand or everything, optionally from a quantity and inside a date
 * window. The sale form is told the best matching rule per product (see App\Services\ProductDiscountRules).
 */
class ProductDiscountRule extends Model
{
    use Auditable;
    use ScopedToCompany;
    use SoftDeletes;

    public const SCOPES = ['product', 'category', 'brand', 'all'];

    public const TYPES = ['percent', 'fixed'];

    protected $fillable = [
        'company_id', 'name', 'scope', 'product_id', 'category_id', 'brand_id', 'discount_type', 'value', 'min_quantity',
        'starts_on', 'ends_on', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return ['value' => 'float', 'min_quantity' => 'float', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'is_active' => 'boolean'];
    }
}
