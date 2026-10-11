<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Why a stock adjustment wrote stock off (damage, expired, near-expiry disposal, wastage). The adjustment itself is the
 * ordinary completed stock adjustment; this row gives it a reason and a value for the Damage / Expired / Wastage screen.
 */
class StockWriteoff extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const REASONS = ['damage', 'expired', 'near_expiry', 'wastage'];

    protected bool $branchScoped = true;

    protected $fillable = ['company_id', 'branch_id', 'adjustment_id', 'reason', 'total_quantity', 'total_value', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['total_quantity' => 'float', 'total_value' => 'float'];
    }
}
