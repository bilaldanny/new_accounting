<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a bank statement: money in is positive, money out negative. `matched_detail_id` is the posted
 * ledger line (`t_account_details.id`) it was matched to, if any.
 */
class BankStatementLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'txn_date' => 'date:Y-m-d',
            'amount' => 'float',
            'matched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BankStatement, $this>
     */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function isMatched(): bool
    {
        return $this->matched_detail_id !== null;
    }
}
