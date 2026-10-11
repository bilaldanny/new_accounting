<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of a customer's named store-credit balance: `issue` (value of a sale return kept as credit),
 * `refund` (paid out in cash or bank), `apply` (used to settle an invoice). Balance = issued - refunded - applied.
 */
class CustomerStoreCredit extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const TYPES = ['issue', 'refund', 'apply'];

    protected bool $branchScoped = true;

    protected $fillable = ['company_id', 'branch_id', 'contact_id', 'entry_type', 'amount', 'sell_return_id', 'transaction_id', 'payment_id', 'reference', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }
}
