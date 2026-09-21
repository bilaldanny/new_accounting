<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A physical count of one branch's stock. See App\Services\StockTaking for how a sheet is opened,
 * counted and completed; completing it writes a stock adjustment for the differences.
 */
class StockTake extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_COMPLETED = 'completed';

    /**
     * Columns the list may be sorted by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['reference', 'count_date', 'status', 'created_at'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'reference',
        'count_date',
        'status',
        'note',
        'adjustment_id',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'adjustment_id');
    }

    /**
     * @return HasMany<StockTakeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTakeLine::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * The superadmin sees every sheet, a company user their company's; a user tied to a branch (and not
     * the company admin) only that branch's.
     */
    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        if (! $user?->company_id) {
            return $query->whereRaw('0 = 1');
        }

        $query->where('company_id', $user->company_id);

        if ($user->branch_id && ! $user->hasRole('companyadmin')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }
}
