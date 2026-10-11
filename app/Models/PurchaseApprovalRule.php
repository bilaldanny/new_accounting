<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A purchase whose total falls in [min_amount, max_amount] needs every level of `levels` approved in order, each by a
 * user of that level's role (or any approver when the level names no role). The narrowest range, then the higher priority,
 * wins. A purchase no rule matches is approved in the one step it always was.
 */
class PurchaseApprovalRule extends Model
{
    use Auditable;
    use ScopedToCompany;
    use SoftDeletes;

    protected $fillable = ['company_id', 'name', 'min_amount', 'max_amount', 'levels', 'forbid_creator', 'priority', 'is_active'];

    protected function casts(): array
    {
        return ['min_amount' => 'float', 'max_amount' => 'float', 'levels' => 'array', 'forbid_creator' => 'boolean', 'is_active' => 'boolean'];
    }
}
