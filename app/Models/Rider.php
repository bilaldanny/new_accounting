<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A rider / driver who delivers sales. Master data; the dispatch board assigns sales to riders.
 */
class Rider extends Model
{
    use Auditable;
    use ScopedToCompany;
    use SoftDeletes;

    protected $fillable = ['company_id', 'branch_id', 'user_id', 'name', 'phone', 'vehicle_no', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
