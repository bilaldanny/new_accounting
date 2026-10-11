<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One instalment of a loan: its principal and interest part, and the voucher that paid it.
 */
class LoanScheduleRow extends Model
{
    protected $fillable = ['loan_id', 'number', 'due_on', 'principal', 'interest', 'total', 'paid_on', 't_account_id'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'paid_on' => 'date', 'principal' => 'float', 'interest' => 'float', 'total' => 'float'];
    }
}
