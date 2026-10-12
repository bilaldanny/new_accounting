<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The allowance for doubtful debts set at the end of a period (one per company and day).
 */
class AllowanceProvision extends Model
{
    use ScopedToCompany;

    protected $fillable = ['company_id', 'as_of', 'buckets', 'required', 'existing', 'change', 't_account_id', 'created_by'];

    protected function casts(): array
    {
        return ['as_of' => 'date:Y-m-d', 'buckets' => 'array', 'required' => 'float', 'existing' => 'float', 'change' => 'float'];
    }
}
