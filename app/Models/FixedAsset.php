<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * One line of the Fixed Asset Register (Accounts & Finance Phase 3). Book value is cost less accumulated
 * depreciation. An asset carried over from before the register (`source` = opening) arrives with its
 * accumulated depreciation and posts nothing: its balances are in the opening balances already.
 */
class FixedAsset extends Model
{
    use Auditable, SoftDeletes;

    public const STATUS_CWIP = 'cwip';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISPOSED = 'disposed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'in_service_on' => 'date',
            'depreciated_through' => 'date',
            'disposed_on' => 'date',
            'cost' => 'float',
            'salvage_value' => 'float',
            'accumulated_depreciation' => 'float',
            'rate' => 'float',
            'useful_life_months' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<FixedAssetDepreciation, $this>
     */
    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class);
    }

    /**
     * @return HasMany<AssetEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class);
    }

    public function bookValue(): float
    {
        return round((float) $this->cost - (float) $this->accumulated_depreciation, 2);
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

        $query->where('company_id', $user->company_id);

        if ($user->branch_id && ! $user->hasRole('companyadmin')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    /**
     * The next register code of the company, FA-00001 style.
     */
    public static function nextCode(int $companyId): string
    {
        $next = (int) self::withTrashed()->where('company_id', $companyId)->count() + 1;

        do {
            $code = 'FA-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (self::withTrashed()->where('company_id', $companyId)->where('code', $code)->exists());

        return $code;
    }

    /**
     * An asset starts from its category's depreciation settings and salvage percentage; what the form sends wins.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyCategoryDefaults(array $data, AssetCategory $category): array
    {
        $data['method'] ??= $category->method;
        $data['useful_life_months'] ??= $category->useful_life_months;
        $data['rate'] ??= $category->rate;
        $data['in_service_on'] ??= $data['acquired_on'];
        $data['salvage_value'] ??= round((float) $data['cost'] * (float) $category->salvage_percent / 100, 2);
        $data['accumulated_depreciation'] ??= 0;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function assertDepreciationInputs(array $data, bool $checkSalvage = true): void
    {
        $errors = [];
        $method = $data['method'];

        if (in_array($method, ['straight_line', 'declining_balance'], true) && empty($data['useful_life_months'])) {
            $errors['useful_life_months'] = ['The useful life in months is required for this method.'];
        }

        if (in_array($method, ['declining_balance', 'wdv'], true) && empty($data['rate'])) {
            $errors['rate'] = [$method === 'wdv' ? 'The yearly rate % is required for WDV.' : 'The declining factor (for example 2) is required.'];
        }

        if ($checkSalvage && (float) ($data['accumulated_depreciation'] ?? 0) > 0 && empty($data['depreciated_through'])) {
            $errors['depreciated_through'] = ['Say up to which date the accumulated depreciation is complete, so depreciation carries on from the next month.'];
        }

        if ($checkSalvage && (float) $data['salvage_value'] >= (float) $data['cost']) {
            $errors['salvage_value'] = ['The salvage value must be below the cost.'];
        }

        if ($checkSalvage && (float) $data['accumulated_depreciation'] > (float) $data['cost'] - (float) $data['salvage_value']) {
            $errors['accumulated_depreciation'] = ['The accumulated depreciation cannot take the asset below its salvage value.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * An asset can be removed from the register, and its money fields edited, only while nothing was ever posted or
     * depreciated on it.
     */
    public function isDeletable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->source === 'opening'
            && ! $this->depreciations()->exists()
            && ! $this->events()->exists();
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
            'asset_category_id' => $this->asset_category_id,
            'category_name' => $this->category?->name,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'serial_no' => $this->serial_no,
            'status' => $this->status,
            'source' => $this->source,
            'acquired_on' => $this->acquired_on?->toDateString(),
            'in_service_on' => $this->in_service_on?->toDateString(),
            'cost' => $this->cost,
            'salvage_value' => $this->salvage_value,
            'accumulated_depreciation' => $this->accumulated_depreciation,
            'book_value' => $this->bookValue(),
            'method' => $this->method,
            'useful_life_months' => $this->useful_life_months,
            'rate' => $this->rate,
            'depreciated_through' => $this->depreciated_through?->toDateString(),
            'disposed_on' => $this->disposed_on?->toDateString(),
            'deletable' => $this->isDeletable(),
        ];
    }
}
