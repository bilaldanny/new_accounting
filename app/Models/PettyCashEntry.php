<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One movement of a petty cash fund: an `expense` (Dr the expense account, Cr the fund's cash account) or a `replenish`
 * (Dr the cash account, Cr the source bank / cash account).
 */
class PettyCashEntry extends Model
{
    use Auditable;
    use ScopedToCompany;

    protected $fillable = ['company_id', 'branch_id', 'fund_id', 'entry_type', 'entry_date', 'payee', 'description', 'receipt_no', 'amount', 'counter_account_id', 't_account_id', 'created_by'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'amount' => 'float'];
    }
}
