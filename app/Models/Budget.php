<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A budget line (Accounts & Finance Phase 3): a planned amount for a company, optionally narrowed to a branch, a cost
 * center and an expense or revenue account, for one month or (month empty) a whole year, which counts as a twelfth
 * in each month. Planning data only: it posts nothing and no voucher ever points at it.
 */
class Budget extends Model
{
    use Auditable, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount' => 'float',
        ];
    }

    /**
     * @return BelongsTo<CostCenter, $this>
     */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        return $user?->company_id ? $query->where('company_id', $user->company_id) : $query->whereRaw('0 = 1');
    }

    /**
     * What each month of this budget is worth: the amount for a monthly budget, a twelfth for a yearly one.
     */
    public function monthlyAmount(): float
    {
        return $this->month === null ? round((float) $this->amount / 12, 2) : (float) $this->amount;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'cost_center_id' => $this->cost_center_id,
            'cost_center_name' => $this->costCenter ? $this->costCenter->code.' - '.$this->costCenter->name : null,
            'coa_id' => $this->coa_id,
            'account_name' => $this->account ? $this->account->code.' - '.$this->account->name : null,
            'year' => $this->year,
            'month' => $this->month,
            'period' => $this->month === null ? (string) $this->year : sprintf('%d-%02d', $this->year, $this->month),
            'amount' => $this->amount,
            'note' => $this->note,
            'deleted' => $this->deleted_at !== null,
        ];
    }
}
