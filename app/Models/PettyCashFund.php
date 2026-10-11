<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An imprest petty cash fund: a float of a fixed size kept in a cash account. Spending and replenishing are entries of the fund,
 * each backed by a journal voucher; the fund is replenished back up to its imprest amount.
 */
class PettyCashFund extends Model
{
    use Auditable;
    use ScopedToCompany;
    use SoftDeletes;

    protected $fillable = ['company_id', 'branch_id', 'name', 'imprest_amount', 'cash_account_id', 'custodian_user_id', 'is_active'];

    protected function casts(): array
    {
        return ['imprest_amount' => 'float', 'is_active' => 'boolean'];
    }
}
