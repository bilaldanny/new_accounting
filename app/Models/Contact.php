<?php

namespace App\Models;

use App\Services\ContactChartOfAccountLinker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Contact extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'currency_id',
        'country_id',
        'state_id',
        'city_id',
        'link_id',
        'prefix',
        'first_name',
        'middle_name',
        'last_name',
        'business_name',
        'gl_id',
        'supplier_gl_id',
        'customer_gl_id',
        'pay_term',
        'pay_type',
        'credit_limit',
        'email',
        'mobile',
        'alternate_no',
        'landline',
        'landmark',
        'active',
        'link_account',
        'address',
        'code',
        'user_type',
        'type',
        'ntn_number',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'link_account' => 'boolean',
            'credit_limit' => 'integer',
        ];
    }

    protected function active(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === 1 || $value === '1' || $value === true,
            set: fn ($value) => ($value === 'false' || $value === false || $value === 0 || $value === '0') ? 0 : 1,
        );
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

    protected function currencyId(): Attribute
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
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function scopeSuppliers(Builder $query): Builder
    {
        return $query->where(function (Builder $sub) {
            $sub->where('user_type', 'supplier')
                ->orWhere('user_type', 'both');
        });
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        if ($user?->company_id) {
            $query->where('company_id', $user->company_id);
        }

        if ($user?->branch_id && ! $user?->hasRole('companyadmin')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    public static function findVisibleSupplier(int $id): ?self
    {
        return self::query()
            ->suppliers()
            ->visibleToCurrentUser()
            ->find($id);
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    public function getDisplayNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->prefix,
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));
    }

    public static function generateCode(int $companyId, ?int $branchId = null): string
    {
        $setting = CompanySetting::query()
            ->where('company_id', $companyId)
            ->first();

        $prefix = $setting?->supplier ?: 'SU';

        $query = self::query()->where('company_id', $companyId);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $count = $query->count();

        return $prefix.'-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function fillFromRequest(object $request, self $contact, array $attributes = []): self
    {
        $contact->company_id = self::resolveScopedId($request->company_id);
        $contact->branch_id = self::resolveScopedId($request->branch_id);
        $contact->currency_id = self::resolveScopedId($request->currency_id ?? null);
        $contact->country_id = self::resolveScopedId($request->country_id ?? null);
        $contact->state_id = self::resolveScopedId($request->state_id ?? null);
        $contact->city_id = self::resolveScopedId($request->city_id ?? null);
        $contact->prefix = $request->prefix ?? null;
        $contact->first_name = $request->first_name;
        $contact->middle_name = $request->middle_name ?? null;
        $contact->last_name = $request->last_name ?? null;
        $contact->business_name = $request->business_name;
        $contact->pay_term = $request->pay_term ?? null;
        $contact->pay_type = $request->pay_type ?? 'day';
        $contact->credit_limit = $request->credit_limit ?? 0;
        $contact->email = $request->email ?? null;
        $contact->mobile = $request->mobile;
        $contact->alternate_no = $request->alternate_no ?? null;
        $contact->landline = $request->landline ?? null;
        $contact->landmark = $request->landmark ?? null;
        $contact->active = $request->active ?? true;
        $contact->address = $request->address ?? '';
        $contact->type = $request->type ?? 'local';
        $contact->ntn_number = $request->ntn_number ?? '';

        foreach ($attributes as $key => $value) {
            $contact->{$key} = $value;
        }

        $contact->save();

        return $contact;
    }

    public static function createSupplier(object $request): self
    {
        $companyId = self::resolveScopedId($request->company_id);
        $branchId = self::resolveScopedId($request->branch_id);

        if ($companyId === null || $branchId === null) {
            throw ValidationException::withMessages([
                'company_id' => ['Company and branch are required.'],
            ]);
        }

        $userType = $request->user_type ?? 'supplier';

        if (! in_array($userType, ['supplier', 'both'], true)) {
            throw ValidationException::withMessages([
                'user_type' => ['Invalid supplier type.'],
            ]);
        }

        app(ContactChartOfAccountLinker::class)->linkForCreate($request);

        $contact = new self;

        return self::fillFromRequest($request, $contact, [
            'code' => self::generateCode($companyId, $branchId),
            'user_type' => $userType,
            'link_account' => $request->link_account ?? 0,
            'supplier_gl_id' => $request->supplier_gl_id ?? null,
            'customer_gl_id' => $request->customer_gl_id ?? null,
            'gl_id' => $request->gl_id ?? null,
        ]);
    }

    public static function updateSupplier(object $request, int $id): self
    {
        $contact = self::findVisibleSupplier($id);

        if ($contact === null) {
            abort(404);
        }

        return self::fillFromRequest($request, $contact);
    }

    public static function linkSupplierToChartOfAccount(int $id): self
    {
        $contact = self::findVisibleSupplier($id);

        if ($contact === null) {
            abort(404);
        }

        return app(ContactChartOfAccountLinker::class)->linkExistingSupplier($contact);
    }

    public static function deleteSupplier(int $id): void
    {
        $contact = self::findVisibleSupplier($id);

        if ($contact !== null) {
            $contact->delete();
        }
    }
}
