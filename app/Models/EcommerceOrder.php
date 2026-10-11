<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A web-shop order as it was received (the payload is kept), and the sale it became.
 */
class EcommerceOrder extends Model
{
    use ScopedToCompany;

    protected $fillable = ['company_id', 'ecommerce_connection_id', 'external_id', 'status', 'payload', 'transaction_id', 'error', 'imported_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'imported_at' => 'datetime'];
    }
}
