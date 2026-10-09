<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * A fixed-asset category (Accounts & Finance Phase 3): the depreciation defaults its assets start from and the
 * chart-of-accounts accounts they post to.
 */
class AssetCategory extends Model
{
    use Auditable;

    public const METHODS = ['straight_line', 'declining_balance', 'wdv'];

    /**
     * @var list<string>
     */
    public const REQUIRED_ACCOUNTS = ['asset_coa_id', 'accumulated_coa_id', 'expense_coa_id'];

    /**
     * @var list<string>
     */
    public const OPTIONAL_ACCOUNTS = ['cwip_coa_id', 'revaluation_coa_id', 'impairment_coa_id', 'disposal_coa_id', 'transfer_coa_id'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'useful_life_months' => 'integer',
            'rate' => 'float',
            'salvage_percent' => 'float',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<FixedAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class);
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
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $columns = [...self::REQUIRED_ACCOUNTS, ...self::OPTIONAL_ACCOUNTS];
        $accounts = ChartOfAccount::query()
            ->whereIn('id', array_filter(array_map(fn (string $column) => $this->{$column}, $columns)))
            ->pluck('name', 'id');

        $row = [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'method' => $this->method,
            'useful_life_months' => $this->useful_life_months,
            'rate' => $this->rate,
            'salvage_percent' => $this->salvage_percent,
            'active' => $this->active,
            'asset_count' => $this->assets()->count(),
        ];

        foreach ($columns as $column) {
            $row[$column] = $this->{$column};
            $row[str_replace('_coa_id', '_account', $column)] = $this->{$column} ? ($accounts[$this->{$column}] ?? null) : null;
        }

        return $row;
    }
}
