<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One reminder made for an invoice at a level (sent, logged for manual follow-up, or failed / no email address).
 */
class DunningEvent extends Model
{
    use ScopedToCompany;

    protected $fillable = [
        'company_id', 'branch_id', 'contact_id', 'transaction_id', 'level', 'channel', 'status', 'recipient', 'subject', 'body', 'amount',
        'promised_on', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'promised_on' => 'date:Y-m-d'];
    }
}
