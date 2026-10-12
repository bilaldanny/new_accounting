<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A company's bad debt accounts and the allowance rate per aging bucket (percent of what is owed in the bucket).
 */
class BadDebtSetting extends Model
{
    public const BUCKETS = ['not_due', 'days_0_30', 'days_31_60', 'days_61_90', 'days_90_plus'];

    protected $fillable = ['company_id', 'expense_account_id', 'allowance_account_id', 'rates'];

    protected function casts(): array
    {
        return ['rates' => 'array'];
    }
}
