<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Payment terms such as `2/10 net 30`: due after `net_days`, with `discount_percent` off when paid within `discount_days`.
 */
class PaymentTerm extends Model
{
    use Auditable;
    use ScopedToCompany;
    use SoftDeletes;

    protected $fillable = ['company_id', 'name', 'net_days', 'discount_percent', 'discount_days', 'is_active'];

    protected function casts(): array
    {
        return ['discount_percent' => 'float', 'is_active' => 'boolean'];
    }
}
