<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A cost center or profit center (Accounts & Finance Phase 3): a place costs (and, for a profit center, revenue) are
 * attributed to, independent of the branch. Centers form a tree through `parent_id`; one may belong to a branch and to a
 * department, which is what the department-wise view of the books groups by. Ledger lines point at one through
 * `t_account_details.cost_center_id`.
 */
class CostCenter extends Model
{
    use Auditable, SoftDeletes;

    public const TYPES = ['cost', 'profit'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsTo<CostCenter, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<CostCenter, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
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
     * Whether this center is `$id` or sits somewhere under it.
     */
    public function isInside(int $id): bool
    {
        $node = $this;

        for ($depth = 0; $depth < 50 && $node !== null; $depth++) {
            if ((int) $node->id === $id) {
                return true;
            }

            $node = $node->parent_id ? self::query()->find($node->parent_id) : null;
        }

        return false;
    }

    /**
     * Whether any ledger line, fixed asset or budget points at this center.
     */
    public function isUsed(): bool
    {
        return TAccountDetail::query()->where('cost_center_id', $this->id)->exists()
            || FixedAsset::withTrashed()->where('cost_center_id', $this->id)->exists()
            || Budget::withTrashed()->where('cost_center_id', $this->id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'parent_id' => $this->parent_id,
            'parent_name' => $this->parent?->name,
            'code' => $this->code,
            'name' => $this->name,
            'text' => $this->code.' - '.$this->name,
            'type' => $this->type,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'department_id' => $this->department_id,
            'department_name' => $this->department?->name,
            'active' => $this->active,
            'used' => $this->isUsed(),
        ];
    }
}
