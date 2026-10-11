<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A web shop (Shopify, WooCommerce, or the built-in `mock` used for tests and demos) whose orders are pulled in as sales.
 * Credentials are stored encrypted and never returned by the API.
 */
class EcommerceConnection extends Model
{
    use Auditable;
    use ScopedToCompany;
    use SoftDeletes;

    public const PLATFORMS = ['shopify', 'woocommerce', 'mock'];

    protected $hidden = ['credentials'];

    protected $fillable = ['company_id', 'branch_id', 'platform', 'name', 'store_url', 'credentials', 'default_contact_id', 'is_active', 'last_synced_at'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_active' => 'boolean', 'last_synced_at' => 'datetime'];
    }
}
