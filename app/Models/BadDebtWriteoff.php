<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A request to write an invoice (or part of it) off as a bad debt; posted when approved. See Services\BadDebt.
 */
class BadDebtWriteoff extends Model
{
    use Auditable;
    use ScopedToCompany;

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'transaction_id', 'contact_id', 'amount', 'written_off_on', 'method', 'reason', 'status', 'payment_id',
        'requested_by', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'written_off_on' => 'date:Y-m-d', 'decided_at' => 'datetime'];
    }
}
