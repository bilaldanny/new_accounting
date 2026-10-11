<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A cheque on its way through its life: received (or issued), deposited, cleared or bounced, or cancelled. The record
 * follows the cheque; when it is linked to a payment, a bounce takes that payment (and its voucher) off through the
 * ordinary payment removal so the invoice is due again.
 */
class ChequeRecord extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const DIRECTIONS = ['received', 'issued'];

    public const STATUSES = ['received', 'issued', 'deposited', 'cleared', 'bounced', 'cancelled'];

    /** @var array<string, list<string>> */
    public const FLOW = [
        'received' => ['deposited', 'bounced', 'cancelled'],
        'issued' => ['cleared', 'bounced', 'cancelled'],
        'deposited' => ['cleared', 'bounced'],
        'cleared' => [],
        'bounced' => [],
        'cancelled' => [],
    ];

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'direction', 'cheque_no', 'bank_name', 'contact_id', 'amount', 'cheque_date', 'due_date', 'status',
        'deposited_on', 'cleared_on', 'bounced_on', 'bounce_reason', 'payment_id', 'transaction_id', 'bank_account_id', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float', 'cheque_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'deposited_on' => 'date:Y-m-d',
            'cleared_on' => 'date:Y-m-d', 'bounced_on' => 'date:Y-m-d',
        ];
    }
}
