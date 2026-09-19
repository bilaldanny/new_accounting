<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Consumer extends Model
{
    use SoftDeletes;

    public const CONSUMER_TYPES = ['individual', 'business'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'account_id',
        'country_id',
        'city',
        'name',
        'address',
        'store_address',
        'contact_person',
        'phone_res',
        'phone_off',
        'fax_no',
        'email',
        'ntn_no',
        'cnic_no',
        'sales_tax_no',
        'consumer_type',
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

    protected function accountId(): Attribute
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
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
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

    public static function createConsumer($request): self
    {
        $companyId = self::resolveScopedId($request->company_id);

        if ($companyId === null && Auth::user()?->company_id) {
            $companyId = (int) Auth::user()->company_id;
        }

        $consumer = new self;
        $consumer->company_id = $companyId;
        $consumer->branch_id = self::resolveScopedId($request->branch_id);
        $consumer->account_id = self::resolveScopedId($request->account_id);
        $consumer->country_id = self::resolveScopedId($request->country_id);
        $consumer->city = $request->city;
        $consumer->name = $request->name;
        $consumer->address = $request->address;
        $consumer->store_address = $request->store_address;
        $consumer->contact_person = $request->contact_person;
        $consumer->phone_res = $request->phone_res;
        $consumer->phone_off = $request->phone_off;
        $consumer->fax_no = $request->fax_no;
        $consumer->email = $request->email;
        $consumer->ntn_no = $request->ntn_no;
        $consumer->cnic_no = $request->cnic_no;
        $consumer->sales_tax_no = $request->sales_tax_no;
        $consumer->consumer_type = $request->consumer_type ?: 'individual';
        $consumer->is_active = $request->has('is_active') ? $request->boolean('is_active') : true;
        $consumer->save();

        return $consumer;
    }

    public static function updateConsumer($request, $id): self
    {
        $consumer = self::query()->visibleToCurrentUser()->findOrFail($id);
        $companyId = self::resolveScopedId($request->company_id);

        if ($companyId === null && Auth::user()?->company_id) {
            $companyId = (int) Auth::user()->company_id;
        }

        $consumer->company_id = $companyId ?? $consumer->company_id;
        $consumer->branch_id = self::resolveScopedId($request->branch_id);
        $consumer->account_id = self::resolveScopedId($request->account_id);
        $consumer->country_id = self::resolveScopedId($request->country_id);
        $consumer->city = $request->city;
        $consumer->name = $request->name;
        $consumer->address = $request->address;
        $consumer->store_address = $request->store_address;
        $consumer->contact_person = $request->contact_person;
        $consumer->phone_res = $request->phone_res;
        $consumer->phone_off = $request->phone_off;
        $consumer->fax_no = $request->fax_no;
        $consumer->email = $request->email;
        $consumer->ntn_no = $request->ntn_no;
        $consumer->cnic_no = $request->cnic_no;
        $consumer->sales_tax_no = $request->sales_tax_no;
        $consumer->consumer_type = $request->consumer_type ?: $consumer->consumer_type;
        $consumer->is_active = $request->has('is_active') ? $request->boolean('is_active') : $consumer->is_active;
        $consumer->save();

        return $consumer;
    }

    public static function deleteConsumer($id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }
}
