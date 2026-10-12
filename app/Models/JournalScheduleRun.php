<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One occurrence a journal schedule posted (kind post) or the reversal of one (kind reversal).
 */
class JournalScheduleRun extends Model
{
    protected $fillable = ['company_id', 'journal_schedule_id', 'period_date', 'kind', 't_account_id', 'amount', 'state'];

    protected function casts(): array
    {
        return ['period_date' => 'date:Y-m-d', 'amount' => 'float'];
    }
}
