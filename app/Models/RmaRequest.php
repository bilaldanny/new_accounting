<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A return merchandise authorisation with a supplier: requested, authorised (their RMA number), inspected, shipped back,
 * received by the supplier, credited, closed. The return itself is still the purchase return document.
 */
class RmaRequest extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const STATUSES = ['requested', 'authorized', 'inspected', 'shipped', 'received', 'credited', 'closed', 'rejected'];

    /**
     * The steps a request may take from each status.
     *
     * @var array<string, list<string>>
     */
    public const FLOW = [
        'requested' => ['authorized', 'rejected'],
        'authorized' => ['inspected', 'shipped', 'closed'],
        'inspected' => ['shipped', 'closed'],
        'shipped' => ['received'],
        'received' => ['credited', 'closed'],
        'credited' => ['closed'],
        'closed' => [],
        'rejected' => [],
    ];

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'contact_id', 'transaction_id', 'rma_no', 'supplier_rma_no', 'status', 'reason', 'inspection_note',
        'authorized_on', 'shipped_on', 'received_on', 'purchase_return_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['authorized_on' => 'date:Y-m-d', 'shipped_on' => 'date:Y-m-d', 'received_on' => 'date:Y-m-d'];
    }

    /**
     * @return HasMany<RmaLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RmaLine::class);
    }
}
