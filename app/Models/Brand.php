<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Brand extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'active',
    ];

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

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function normalizeName(?string $name): string
    {
        return strtolower(preg_replace('/\s+/', '', trim((string) $name)));
    }

    public static function nameExists(
        string $name,
        ?int $exceptId = null,
        ?int $companyId = null,
    ): bool {
        return self::query()
            ->when($exceptId !== null, fn (Builder $query) => $query->where('id', '!=', $exceptId))
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->whereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [self::normalizeName($name)])
            ->exists();
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

    public static function findVisibleToCurrentUser(int $id): ?self
    {
        return self::query()->visibleToCurrentUser()->find($id);
    }

    /**
     * @throws ValidationException
     */
    public static function assertUniqueName(
        string $name,
        ?int $exceptId = null,
        ?int $companyId = null,
    ): void {
        if (self::nameExists($name, $exceptId, $companyId)) {
            throw ValidationException::withMessages([
                'name' => ['A brand with this name already exists.'],
            ]);
        }
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    public static function createBrand(object $request): self
    {
        $companyId = self::resolveScopedId($request->company_id);

        self::assertUniqueName($request->name, null, $companyId);

        $brand = new self;
        $brand->company_id = $companyId;
        $brand->name = $request->name;
        $brand->active = $request->active ?? true;
        $brand->save();

        return $brand;
    }

    public static function updateBrand(object $request, int $id): self
    {
        $brand = self::findVisibleToCurrentUser($id);

        if ($brand === null) {
            abort(404);
        }

        $companyId = self::resolveScopedId($request->company_id);

        self::assertUniqueName($request->name, $id, $companyId);

        $brand->company_id = $companyId;
        $brand->name = $request->name;
        $brand->active = $request->active ?? true;
        $brand->save();

        return $brand;
    }

    public static function deleteBrand(int $id): void
    {
        $brand = self::findVisibleToCurrentUser($id);

        if ($brand === null) {
            return;
        }

        $brand->delete();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function upsertFromImport(array $row): string
    {
        $id = self::normalizeImportId($row['id'] ?? null);
        $payload = self::buildImportPayload($row, $id);

        if ($id !== null) {
            $brand = self::findVisibleToCurrentUser($id);

            if ($brand === null) {
                throw ValidationException::withMessages([
                    'rows' => ["Brand with id {$id} was not found."],
                ]);
            }

            self::updateBrand($payload, $id);

            return 'updated';
        }

        self::createBrand($payload);

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
    protected static function buildImportPayload(array $row, ?int $existingId = null): object
    {
        $companyId = self::resolveScopedId($row['company_id'] ?? null);

        if ($companyId === null && Auth::user()?->company_id) {
            $companyId = (int) Auth::user()->company_id;
        }

        if ($existingId !== null) {
            $existingBrand = self::findVisibleToCurrentUser($existingId);

            if ($existingBrand !== null && $companyId === null) {
                $companyId = self::resolveScopedId($existingBrand->company_id);
            }
        }

        $active = $row['active'] ?? $row['is_active'] ?? 1;

        return (object) [
            'company_id' => $companyId,
            'name' => (string) ($row['name'] ?? ''),
            'active' => self::normalizeImportBool($active),
        ];
    }

    protected static function normalizeImportBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
