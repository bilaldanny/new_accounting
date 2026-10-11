<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A loan or financing facility with its repayment schedule. The disbursement and each repayment are journal vouchers.
 */
class Loan extends Model
{
    use Auditable;
    use ScopedToCompany;

    protected $fillable = ['company_id', 'branch_id', 'name', 'lender', 'principal', 'annual_rate', 'installments', 'frequency', 'start_date', 'loan_account_id', 'interest_account_id', 'bank_account_id', 'disbursement_t_account_id', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['principal' => 'float', 'annual_rate' => 'float', 'start_date' => 'date'];
    }

    /**
     * @return HasMany<LoanScheduleRow, $this>
     */
    public function schedule(): HasMany
    {
        return $this->hasMany(LoanScheduleRow::class)->orderBy('number');
    }
}
