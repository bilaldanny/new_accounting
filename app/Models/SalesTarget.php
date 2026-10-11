<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A sales target for one subject (a user, a commission agent or a cashier) in one month (`YYYY-MM`).
 */
class SalesTarget extends Model
{
    use ScopedToCompany;

    public const SUBJECTS = ['user', 'agent', 'cashier'];

    protected $fillable = ['company_id', 'subject_type', 'subject_id', 'period', 'target_amount', 'created_by'];

    protected function casts(): array
    {
        return ['target_amount' => 'float'];
    }
}
