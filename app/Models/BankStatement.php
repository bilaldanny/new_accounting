<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * The bank's statement for one bank account and period, being reconciled against the ledger
 * (see App\Services\BankReconciliation).
 */
class BankStatement extends Model
{
    use Auditable, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RECONCILED = 'reconciled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'statement_from' => 'date:Y-m-d',
            'statement_to' => 'date:Y-m-d',
            'opening_balance' => 'float',
            'closing_balance' => 'float',
            'reconciled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BankStatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('txn_date')->orderBy('id');
    }

    public function isReconciled(): bool
    {
        return $this->status === self::STATUS_RECONCILED;
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        if (! $user?->company_id) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('company_id', $user->company_id);
    }
}
