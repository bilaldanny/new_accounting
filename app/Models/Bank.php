<?php

namespace App\Models;

use App\Services\BankChartOfAccountLinker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Bank extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'first_name',
        'bank_name',
        'prefix',
        'middle_name',
        'last_name',
        'gl_id',
        'email',
        'mobile',
        'alternate_no',
        'landline',
        'landmark',
        'country_id',
        'state_id',
        'city_id',
        'active',
        'link_account',
        'address',
        'code',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'link_account' => 'boolean',
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

    public static function findVisibleBank(int $id): ?self
    {
        return self::query()
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

    public static function bankPrefix(int $companyId): string
    {
        $setting = CompanySetting::query()
            ->where('company_id', $companyId)
            ->first();

        return $setting?->bank ?: 'BK';
    }

    public static function formatBankCode(string $prefix, int $number): string
    {
        return $prefix.'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    public static function extractBankCodeNumber(string $code, string $prefix): int
    {
        $normalized = strtoupper(str_replace(' ', '', trim($code)));
        $pattern = '/^'.preg_quote(strtoupper($prefix), '/').'-(\d+)$/';

        if (preg_match($pattern, $normalized, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    public static function codeExists(string $code, int $companyId, ?int $branchId = null, ?int $exceptId = null): bool
    {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        return self::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($exceptId !== null, fn (Builder $query) => $query->where('id', '!=', $exceptId))
            ->where('code', $code)
            ->exists();
    }

    public static function nextCode(int $companyId, ?int $branchId = null): string
    {
        $prefix = self::bankPrefix($companyId);

        $query = self::query()
            ->withTrashed()
            ->where('company_id', $companyId);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $maxNumber = $query
            ->pluck('code')
            ->map(fn (string $code) => self::extractBankCodeNumber($code, $prefix))
            ->max() ?? 0;

        $nextNumber = $maxNumber + 1;
        $code = self::formatBankCode($prefix, $nextNumber);

        while (self::codeExists($code, $companyId, $branchId)) {
            $nextNumber++;
            $code = self::formatBankCode($prefix, $nextNumber);
        }

        return $code;
    }

    public static function generateCode(int $companyId, ?int $branchId = null): string
    {
        return self::nextCode($companyId, $branchId);
    }

    public static function resolveBankCode(object $request, int $companyId, ?int $branchId): string
    {
        $code = trim((string) ($request->code ?? ''));

        if ($code === '') {
            return self::nextCode($companyId, $branchId);
        }

        if (self::codeExists($code, $companyId, $branchId)) {
            throw ValidationException::withMessages([
                'code' => ['Bank code already exists.'],
            ]);
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function fillFromRequest(object $request, self $bank, array $attributes = []): self
    {
        $bank->company_id = self::resolveScopedId($request->company_id);
        $bank->branch_id = self::resolveScopedId($request->branch_id);
        $bank->country_id = self::resolveScopedId($request->country_id ?? null);
        $bank->state_id = self::resolveScopedId($request->state_id ?? null);
        $bank->city_id = self::resolveScopedId($request->city_id ?? null);
        $bank->prefix = $request->prefix ?? null;
        $bank->first_name = $request->first_name;
        $bank->middle_name = $request->middle_name ?? null;
        $bank->last_name = $request->last_name ?? null;
        $bank->bank_name = $request->bank_name;
        $bank->email = $request->email ?? null;
        $bank->mobile = $request->mobile;
        $bank->alternate_no = $request->alternate_no ?? null;
        $bank->landline = $request->landline ?? null;
        $bank->landmark = $request->landmark ?? null;
        $bank->active = $request->active ?? true;
        $bank->address = $request->address ?? null;
        $bank->type = $request->type ?? 'local';

        foreach ($attributes as $key => $value) {
            $bank->{$key} = $value;
        }

        $bank->save();

        return $bank;
    }

    public static function createBank(object $request): self
    {
        $companyId = self::resolveScopedId($request->company_id);
        $branchId = self::resolveScopedId($request->branch_id);

        if ($companyId === null || $branchId === null) {
            throw ValidationException::withMessages([
                'company_id' => ['Company and branch are required.'],
            ]);
        }

        app(BankChartOfAccountLinker::class)->linkForCreate($request);

        $bank = new self;

        return self::fillFromRequest($request, $bank, [
            'code' => self::resolveBankCode($request, $companyId, $branchId),
            'link_account' => $request->link_account ?? 0,
            'gl_id' => $request->gl_id ?? null,
        ]);
    }

    public static function updateBank(object $request, int $id): self
    {
        $bank = self::findVisibleBank($id);

        if ($bank === null) {
            abort(404);
        }

        return self::fillFromRequest($request, $bank);
    }

    public static function linkBankToChartOfAccount(int $id, float $openingBalance = 0): self
    {
        $bank = self::findVisibleBank($id);

        if ($bank === null) {
            abort(404);
        }

        return app(BankChartOfAccountLinker::class)->linkExistingBank($bank, $openingBalance);
    }

    public static function deleteBank(int $id): void
    {
        $bank = self::findVisibleBank($id);

        if ($bank !== null) {
            $bank->delete();
        }
    }
}
