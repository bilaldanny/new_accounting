<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Branch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'company_id',
        'country_id',
        'state_id',
        'city_id',
        'name',
        'description',
        'phone',
        'mobile',
        'email',
        'address',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected function companyId(): Attribute
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

    public static function nextCode(): string
    {
        $maxNumber = self::query()
            ->withTrashed()
            ->where('code', 'like', 'BR-%')
            ->pluck('code')
            ->map(fn (string $code) => self::extractCodeNumber($code))
            ->max() ?? 0;

        $nextNumber = $maxNumber + 1;
        $code = self::formatCode($nextNumber);

        while (self::codeExists($code)) {
            $nextNumber++;
            $code = self::formatCode($nextNumber);
        }

        return $code;
    }

    public static function formatCode(int $number): string
    {
        return sprintf('BR-%05d', $number);
    }

    public static function extractCodeNumber(string $code): int
    {
        $normalized = strtoupper(str_replace(' ', '', trim($code)));

        if (preg_match('/^BR-(\d+)$/', $normalized, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    public static function normalizeCode(string $code): string
    {
        $normalized = strtoupper(str_replace(' ', '', trim($code)));

        if (preg_match('/^BR-(\d+)$/', $normalized, $matches)) {
            return self::formatCode((int) $matches[1]);
        }

        return $normalized;
    }

    public static function resolveCode(?string $code): string
    {
        $normalized = self::normalizeCode((string) $code);

        if ($normalized !== '') {
            return $normalized;
        }

        return self::nextCode();
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $normalized = self::normalizeCode($code);

        if ($normalized === '') {
            return false;
        }

        return self::query()
            ->withTrashed()
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where('code', $normalized)
            ->exists();
    }

    /** @deprecated Use nextCode() instead */
    public static function generateCode(): string
    {
        return self::nextCode();
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

        if ($user?->company_id) {
            return $query->where('company_id', $user->company_id);
        }

        return $query;
    }

    public static function canAddBranchForCompany(?int $companyId): bool
    {
        if ($companyId === null) {
            return true;
        }

        $company = Company::find($companyId);

        if ($company === null) {
            return true;
        }

        $count = self::query()->where('company_id', $companyId)->count();

        return $count < (int) $company->max_branches;
    }

    /**
     * @throws ValidationException
     */
    public static function assertWithinBranchLimit(int $companyId): void
    {
        if (self::canAddBranchForCompany($companyId)) {
            return;
        }

        throw ValidationException::withMessages([
            'company_id' => ['Maximum branch limit reached for this company.'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     *
     * @throws ValidationException
     */
    public static function assertImportWithinBranchLimit(array $rows): void
    {
        $newRowsByCompany = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if (self::normalizeImportId($row['id'] ?? null) !== null) {
                continue;
            }

            $companyId = self::resolveScopedId($row['company_id'] ?? null);

            if ($companyId === null && Auth::user()?->company_id) {
                $companyId = (int) Auth::user()->company_id;
            }

            if ($companyId === null) {
                throw ValidationException::withMessages([
                    'rows' => ['Row '.($index + 1).': company_id is required for new branch imports.'],
                ]);
            }

            $newRowsByCompany[$companyId] = ($newRowsByCompany[$companyId] ?? 0) + 1;
        }

        foreach ($newRowsByCompany as $companyId => $newCount) {
            $company = Company::query()->find($companyId);

            if ($company === null) {
                throw ValidationException::withMessages([
                    'rows' => ["Company with id {$companyId} was not found."],
                ]);
            }

            $existingCount = self::query()->where('company_id', $companyId)->count();
            $maxBranches = (int) $company->max_branches;

            if ($existingCount + $newCount > $maxBranches) {
                $remaining = max(0, $maxBranches - $existingCount);

                throw ValidationException::withMessages([
                    'rows' => [
                        sprintf(
                            'Import exceeds the branch limit for company "%s". Maximum allowed: %d, existing: %d, importing: %d new row(s). You can add at most %d more branch(es).',
                            $company->name,
                            $maxBranches,
                            $existingCount,
                            $newCount,
                            $remaining,
                        ),
                    ],
                ]);
            }
        }
    }

    /**
     * Ensure only one branch is default per company.
     */
    public static function clearOtherDefaults(int $companyId, int $exceptBranchId): void
    {
        self::query()
            ->where('company_id', $companyId)
            ->where('id', '!=', $exceptBranchId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Promote another branch when the current default is removed.
     */
    public static function promoteNextDefault(int $companyId, ?int $exceptBranchId = null): void
    {
        $hasDefault = self::query()
            ->where('company_id', $companyId)
            ->when($exceptBranchId !== null, fn ($query) => $query->where('id', '!=', $exceptBranchId))
            ->where('is_default', true)
            ->exists();

        if ($hasDefault) {
            return;
        }

        $nextDefault = self::query()
            ->where('company_id', $companyId)
            ->when($exceptBranchId !== null, fn ($query) => $query->where('id', '!=', $exceptBranchId))
            ->orderBy('id')
            ->first();

        if ($nextDefault !== null) {
            $nextDefault->is_default = true;
            $nextDefault->save();
        }
    }

    public static function createBranch($request): self
    {
        $companyId = self::resolveScopedId($request->company_id);

        if ($companyId === null && Auth::user()?->company_id) {
            $companyId = (int) Auth::user()->company_id;
        }

        if ($companyId !== null) {
            self::assertWithinBranchLimit($companyId);
        }

        $isFirstBranchForCompany = $companyId !== null
            && ! self::query()->where('company_id', $companyId)->exists();

        $branch = new self;
        $branch->code = self::resolveCode($request->input('code'));
        $branch->company_id = $companyId;
        $branch->country_id = self::resolveScopedId($request->country_id);
        $branch->state_id = self::resolveScopedId($request->state_id);
        $branch->city_id = self::resolveScopedId($request->city_id);
        $branch->name = $request->name;
        $branch->description = $request->description;
        $branch->phone = $request->phone;
        $branch->mobile = $request->mobile;
        $branch->email = $request->email;
        $branch->address = $request->address;
        $branch->is_active = $request->has('is_active') ? $request->boolean('is_active') : true;
        $branch->is_default = $isFirstBranchForCompany || $request->boolean('is_default');
        $branch->save();

        if ($branch->is_default && $companyId !== null) {
            self::clearOtherDefaults($companyId, (int) $branch->id);
        }

        if ($companyId !== null) {
            parentChartOfAccount($companyId, (int) $branch->id);
            accountMapping($companyId, (int) $branch->id);
        }

        return $branch;
    }

    public static function updateBranch($request, $id): self
    {
        $branch = self::query()->visibleToCurrentUser()->findOrFail($id);
        $companyId = self::resolveScopedId($request->company_id);

        if ($companyId === null && Auth::user()?->company_id) {
            $companyId = (int) Auth::user()->company_id;
        }

        $branch->company_id = $companyId ?? $branch->company_id;
        $branch->country_id = self::resolveScopedId($request->country_id);
        $branch->state_id = self::resolveScopedId($request->state_id);
        $branch->city_id = self::resolveScopedId($request->city_id);
        $branch->name = $request->name;
        $branch->description = $request->description;
        $branch->phone = $request->phone;
        $branch->mobile = $request->mobile;
        $branch->email = $request->email;
        $branch->address = $request->address;
        $branch->is_active = $request->has('is_active') ? $request->boolean('is_active') : $branch->is_active;

        $isDefault = $request->has('is_default')
            ? $request->boolean('is_default')
            : $branch->is_default;

        if (! $isDefault && $branch->is_default) {
            $otherDefaultExists = self::query()
                ->where('company_id', $branch->company_id)
                ->where('id', '!=', $branch->id)
                ->where('is_default', true)
                ->exists();

            if (! $otherDefaultExists) {
                throw ValidationException::withMessages([
                    'is_default' => ['Each company must have one default branch. Set another branch as default first.'],
                ]);
            }
        }

        $branch->is_default = $isDefault;
        $branch->save();

        if ($branch->is_default && $branch->company_id) {
            self::clearOtherDefaults((int) $branch->company_id, (int) $branch->id);
        }

        return $branch;
    }

    public static function deleteBranch($id): void
    {
        $branch = self::query()->visibleToCurrentUser()->find($id);

        if ($branch !== null) {
            $companyId = $branch->company_id;
            $branchId = (int) $branch->id;
            $wasDefault = $branch->is_default;

            $branch->delete();

            if ($wasDefault && $companyId) {
                self::promoteNextDefault((int) $companyId, $branchId);
            }
        }
    }

    public static function createCompanyBranch(int $companyId): self
    {
        $branch = new self;
        $branch->code = self::nextCode();
        $branch->company_id = $companyId;
        $branch->name = 'headoffice';
        $branch->is_active = true;
        $branch->is_default = true;
        $branch->save();

        parentChartOfAccount($companyId, (int) $branch->id);
        accountMapping($companyId, (int) $branch->id);

        return $branch;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function upsertFromImport(array $row): string
    {
        $id = self::normalizeImportId($row['id'] ?? null);
        $payload = self::buildImportRequest($row);

        if ($id !== null) {
            $branch = self::query()->visibleToCurrentUser()->find($id);

            if ($branch === null) {
                throw ValidationException::withMessages([
                    'rows' => ["Branch with id {$id} was not found."],
                ]);
            }

            self::updateBranch($payload, $id);

            return 'updated';
        }

        if ($payload->input('name') === '') {
            throw ValidationException::withMessages([
                'rows' => ['Branch name is required for new records.'],
            ]);
        }

        if ($payload->input('email') === '') {
            throw ValidationException::withMessages([
                'rows' => ['Branch email is required for new records.'],
            ]);
        }

        self::createBranch($payload);

        return 'created';
    }

    protected static function normalizeImportId(mixed $id): ?int
    {
        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            return null;
        }

        if (is_numeric($id)) {
            $normalized = (int) $id;

            return $normalized > 0 ? $normalized : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function buildImportRequest(array $row): Request
    {
        $isActive = $row['is_active'] ?? 1;
        $countryId = self::resolveImportCountryId($row['country_id'] ?? $row['country'] ?? null);
        $stateId = self::resolveImportStateId($row['state_id'] ?? $row['state'] ?? null, $countryId);
        $cityId = self::resolveImportCityId($row['city_id'] ?? $row['city'] ?? null, $stateId, $countryId);

        return Request::create('/', 'POST', [
            'code' => isset($row['code']) ? (string) $row['code'] : '',
            'company_id' => self::resolveScopedId($row['company_id'] ?? null),
            'country_id' => $countryId,
            'state_id' => $stateId,
            'city_id' => $cityId,
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'mobile' => (string) ($row['mobile'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'is_active' => self::normalizeImportBool($isActive),
            'is_default' => self::normalizeImportBool($row['is_default'] ?? 0),
        ]);
    }

    protected static function normalizeImportLocationValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $value = (string) (int) $value;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    public static function resolveImportCountryId(mixed $value): ?int
    {
        $normalized = self::normalizeImportLocationValue($value);

        if ($normalized === null) {
            return null;
        }

        if (is_numeric($normalized)) {
            return (int) $normalized;
        }

        $countryId = Country::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($normalized)])
            ->value('id');

        if ($countryId === null) {
            throw ValidationException::withMessages([
                'rows' => ["Country \"{$normalized}\" was not found."],
            ]);
        }

        return (int) $countryId;
    }

    public static function resolveImportStateId(mixed $value, ?int $countryId): ?int
    {
        $normalized = self::normalizeImportLocationValue($value);

        if ($normalized === null) {
            return null;
        }

        if (is_numeric($normalized)) {
            return (int) $normalized;
        }

        $query = State::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($normalized)]);

        if ($countryId !== null) {
            $query->where('country_id', $countryId);
        }

        $matches = $query->pluck('id');

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages([
                'rows' => ["State \"{$normalized}\" was not found."],
            ]);
        }

        if ($matches->count() > 1 && $countryId === null) {
            throw ValidationException::withMessages([
                'rows' => ["State \"{$normalized}\" is ambiguous. Provide country as well."],
            ]);
        }

        return (int) $matches->first();
    }

    public static function resolveImportCityId(mixed $value, ?int $stateId, ?int $countryId): ?int
    {
        $normalized = self::normalizeImportLocationValue($value);

        if ($normalized === null) {
            return null;
        }

        if (is_numeric($normalized)) {
            return (int) $normalized;
        }

        $query = City::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($normalized)]);

        if ($stateId !== null) {
            $query->where('state_id', $stateId);
        } elseif ($countryId !== null) {
            $query->where('country_id', $countryId);
        }

        $matches = $query->pluck('id');

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages([
                'rows' => ["City \"{$normalized}\" was not found."],
            ]);
        }

        if ($matches->count() > 1 && $stateId === null && $countryId === null) {
            throw ValidationException::withMessages([
                'rows' => ["City \"{$normalized}\" is ambiguous. Provide state or country as well."],
            ]);
        }

        return (int) $matches->first();
    }

    protected static function normalizeImportBool(mixed $value): int
    {
        return $value === true || $value === 1 || $value === '1' ? 1 : 0;
    }
}
