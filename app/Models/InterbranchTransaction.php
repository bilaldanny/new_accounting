<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A transfer between two branches of one company and the two vouchers that record it.
 */
class InterbranchTransaction extends Model
{
    use Auditable;
    use ScopedToCompany;

    protected $fillable = [
        'company_id', 'from_branch_id', 'to_branch_id', 'amount', 'transaction_date', 'reference', 'description', 'from_account_id', 'to_account_id',
        'from_voucher_id', 'to_voucher_id', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'transaction_date' => 'date:Y-m-d'];
    }
}
