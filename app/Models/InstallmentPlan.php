<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sale paid over time (`installment`) or goods held until paid (`layaway`). The schedule says what falls due when; payments are
 * the sale's ordinary payments (so the ledger is the usual one) and are allocated to the oldest installment first.
 */
class InstallmentPlan extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const KINDS = ['installment', 'layaway'];

    public const STATUSES = ['active', 'completed', 'defaulted', 'cancelled'];

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'contact_id', 'transaction_id', 'kind', 'status', 'total_amount', 'down_payment', 'installments',
        'frequency', 'first_due_on', 'reservation_ids', 'created_by',
    ];

    protected function casts(): array
    {
        return ['total_amount' => 'float', 'down_payment' => 'float', 'first_due_on' => 'date:Y-m-d', 'reservation_ids' => 'array'];
    }

    /**
     * @return HasMany<InstallmentScheduleRow, $this>
     */
    public function schedule(): HasMany
    {
        return $this->hasMany(InstallmentScheduleRow::class)->orderBy('number');
    }
}
