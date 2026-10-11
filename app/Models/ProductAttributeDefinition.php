<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A custom field a company wants on its products (Colour, Material, Expiry class ...). The value lives on the product
 * (`products.custom_fields`, keyed by `field_key`), so a product with no value for a field simply has no key.
 */
class ProductAttributeDefinition extends Model
{
    use Auditable;
    use ScopedToCompany;
    use SoftDeletes;

    public const TYPES = ['text', 'number', 'date', 'select', 'checkbox'];

    protected $fillable = ['company_id', 'name', 'field_key', 'type', 'options', 'is_required', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array', 'is_required' => 'boolean', 'is_active' => 'boolean'];
    }
}
