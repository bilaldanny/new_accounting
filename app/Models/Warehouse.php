<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Warehouse extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'country_id',
        'state_id',
        'city_id',
        'user_id',
        'name',
        'address',
        'zipcode',
        'phone',
        'fax',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected function companyId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function branchId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function countryId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function stateId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function cityId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function userId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
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
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
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

        if ($user?->branch_id && ! $user?->hasRole('companyadmin')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    public static function createWarehouse($request): self
    {
        $companyId = self::resolveScopedId($request->company_id);

        if ($companyId === null && Auth::user()?->company_id) {
            $companyId = (int) Auth::user()->company_id;
        }

        $warehouse = new self;
        $warehouse->company_id = $companyId;
        $warehouse->branch_id = self::resolveScopedId($request->branch_id);
        $warehouse->country_id = self::resolveScopedId($request->country_id);
        $warehouse->state_id = self::resolveScopedId($request->state_id);
        $warehouse->city_id = self::resolveScopedId($request->city_id);
        $warehouse->user_id = self::resolveScopedId($request->user_id);
        $warehouse->name = $request->name;
        $warehouse->address = $request->address;
        $warehouse->zipcode = $request->zipcode;
        $warehouse->phone = $request->phone;
        $warehouse->fax = $request->fax;
        $warehouse->is_active = $request->has('is_active') ? $request->boolean('is_active') : true;
        $warehouse->save();

        return $warehouse;
    }

    public static function updateWarehouse($request, $id): self
    {
        $warehouse = self::query()->visibleToCurrentUser()->findOrFail($id);
        $companyId = self::resolveScopedId($request->company_id);

        if ($companyId === null && Auth::user()?->company_id) {
            $companyId = (int) Auth::user()->company_id;
        }

        $warehouse->company_id = $companyId ?? $warehouse->company_id;
        $warehouse->branch_id = self::resolveScopedId($request->branch_id);
        $warehouse->country_id = self::resolveScopedId($request->country_id);
        $warehouse->state_id = self::resolveScopedId($request->state_id);
        $warehouse->city_id = self::resolveScopedId($request->city_id);
        $warehouse->user_id = self::resolveScopedId($request->user_id);
        $warehouse->name = $request->name;
        $warehouse->address = $request->address;
        $warehouse->zipcode = $request->zipcode;
        $warehouse->phone = $request->phone;
        $warehouse->fax = $request->fax;
        $warehouse->is_active = $request->has('is_active') ? $request->boolean('is_active') : $warehouse->is_active;
        $warehouse->save();

        return $warehouse;
    }

    public static function deleteWarehouse($id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }
}
