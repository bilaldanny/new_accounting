<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payment to a supplier allocated across several of their bills, with what no bill takes kept as a supplier advance
 * that later bills can be settled from. Every posting goes through the existing Payment paths.
 */
class SupplierPaymentBatch extends Model
{
    use Auditable;
    use ScopedToCompany;

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'contact_id', 'reference', 'paid_on', 'payment_account', 'method', 'amount', 'advance_amount',
        'advance_payment_id', 'status', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['paid_on' => 'date:Y-m-d', 'amount' => 'float', 'advance_amount' => 'float'];
    }

    /**
     * @return HasMany<SupplierPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /**
     * What of the advance has not been used on a bill yet.
     */
    public function advanceRemaining(): float
    {
        $used = (float) $this->allocations()->where('kind', 'advance')->sum('amount');

        return round(max((float) $this->advance_amount - $used - AdvanceRefund::refundedFor('supplier_payment_batch_id', (int) $this->id), 0), 2);
    }
}
