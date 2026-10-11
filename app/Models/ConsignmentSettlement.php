<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a company owes a consigning supplier for the stock of theirs it sold in a period. A statement, not a posting:
 * the supplier bill or voucher is entered through the normal purchase / payment screens and named in `reference`.
 */
class ConsignmentSettlement extends Model
{
    use Auditable;
    use ScopedToCompany;

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'supplier_id', 'settlement_no', 'period_from', 'period_to', 'status', 'total_amount',
        'reference', 'note', 'settled_on', 'created_by',
    ];

    protected function casts(): array
    {
        return ['period_from' => 'date:Y-m-d', 'period_to' => 'date:Y-m-d', 'settled_on' => 'date:Y-m-d', 'total_amount' => 'float'];
    }

    /**
     * @return HasMany<ConsignmentSettlementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ConsignmentSettlementLine::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }
}
