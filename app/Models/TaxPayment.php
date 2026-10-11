<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tax paid to the authority, with the challan it was paid on. Posts Dr the tax payable account, Cr the bank account.
 */
class TaxPayment extends Model
{
    use Auditable;
    use ScopedToCompany;
    use SoftDeletes;

    protected $fillable = ['company_id', 'branch_id', 'tax_type', 'period', 'challan_no', 'bank_name', 'amount', 'paid_on', 'tax_account_id', 'bank_account_id', 't_account_id', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'paid_on' => 'date'];
    }
}
