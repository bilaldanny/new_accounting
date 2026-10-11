<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The cashier master of the POS: a person with a code and a PIN (kept hashed; five wrong PINs lock it for 15 minutes) and
 * daily / monthly sales targets. A POS sale can name its cashier (`transactions.cashier_id`).
 */
class Cashier extends Model
{
    use Auditable;
    use ScopedToCompany;
    use SoftDeletes;

    protected bool $branchScoped = true;

    protected $hidden = ['pin_hash'];

    protected $fillable = ['company_id', 'branch_id', 'user_id', 'name', 'code', 'pin_hash', 'daily_target', 'monthly_target', 'is_active', 'failed_attempts', 'locked_until'];

    protected function casts(): array
    {
        return ['daily_target' => 'float', 'monthly_target' => 'float', 'is_active' => 'boolean', 'locked_until' => 'datetime'];
    }
}
