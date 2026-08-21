<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Unit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'parent_id',
        'name',
        'short_name',
        'type',
        'active',
        'auto_adjustment',
    ];

    protected function active(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === 1 || $value === '1' || $value === true,
            set: fn ($value) => ($value === 'false' || $value === false || $value === 0 || $value === '0') ? 0 : 1,
        );
    }

    protected function autoAdjustment(): Attribute
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

    protected function parentId(): Attribute
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
     * @return BelongsTo<Unit, $this>
     */
    public function parentUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Unit, $this>
     */
    public function childrenUnits(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
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
                'name' => ['A unit with this name already exists.'],
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

    public static function createUnit(object $request): self
    {
        $companyId = self::resolveScopedId($request->company_id);
        $parentId = self::resolveScopedId($request->parent_id);

        self::assertUniqueName($request->name, null, $companyId);

        $unit = new self;
        $unit->company_id = $companyId;
        $unit->parent_id = $parentId;
        $unit->name = $request->name;
        $unit->short_name = $request->short_name;
        $unit->type = $request->type ?? 'large';
        $unit->active = $request->active ?? true;
        $unit->auto_adjustment = $request->auto_adjustment ?? false;
        $unit->save();

        return $unit;
    }

    public static function updateUnit(object $request, int $id): self
    {
        $unit = self::findVisibleToCurrentUser($id);

        if ($unit === null) {
            abort(404);
        }

        $companyId = self::resolveScopedId($request->company_id);
        $parentId = self::resolveScopedId($request->parent_id);

        if ($parentId !== null && $parentId === $id) {
            throw ValidationException::withMessages([
                'parent_id' => ['A unit cannot be its own parent.'],
            ]);
        }

        self::assertUniqueName($request->name, $id, $companyId);

        $unit->company_id = $companyId;
        $unit->parent_id = $parentId;
        $unit->name = $request->name;
        $unit->short_name = $request->short_name;
        $unit->type = $request->type ?? 'large';
        $unit->active = $request->active ?? true;
        $unit->auto_adjustment = $request->auto_adjustment ?? false;
        $unit->save();

        return $unit;
    }

    public static function deleteUnit(int $id): void
    {
        $unit = self::findVisibleToCurrentUser($id);

        if ($unit === null) {
            return;
        }

        if ($unit->parent_id === null) {
            $childIds = self::query()
                ->where('parent_id', $unit->id)
                ->pluck('id');

            self::whereIn('id', $childIds)->delete();
        }

        $unit->delete();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function upsertFromImport(array $row): string
    {
        $id = self::normalizeImportId($row['id'] ?? null);
        $payload = self::buildImportPayload($row, $id);

        if ($id !== null) {
            $unit = self::findVisibleToCurrentUser($id);

            if ($unit === null) {
                throw ValidationException::withMessages([
                    'rows' => ["Unit with id {$id} was not found."],
                ]);
            }

            self::updateUnit($payload, $id);

            return 'updated';
        }

        self::createUnit($payload);

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
            $existingUnit = self::findVisibleToCurrentUser($existingId);

            if ($existingUnit !== null && $companyId === null) {
                $companyId = self::resolveScopedId($existingUnit->company_id);
            }
        }

        $active = $row['active'] ?? $row['is_active'] ?? 1;
        $autoAdjustment = $row['auto_adjustment'] ?? $row['auto_adjust'] ?? 0;
        $type = strtolower(trim((string) ($row['type'] ?? 'large')));

        if (! in_array($type, ['large', 'small'], true)) {
            $type = 'large';
        }

        return (object) [
            'company_id' => $companyId,
            'parent_id' => self::resolveImportParentId(
                $row['parent_id'] ?? $row['parent'] ?? null,
                $companyId,
                $existingId,
            ),
            'name' => (string) ($row['name'] ?? ''),
            'short_name' => (string) ($row['short_name'] ?? ''),
            'type' => $type,
            'active' => self::normalizeImportBool($active),
            'auto_adjustment' => self::normalizeImportBool($autoAdjustment),
        ];
    }

    public static function resolveImportParentId(
        mixed $value,
        ?int $companyId,
        ?int $exceptId = null,
    ): ?int {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        if (is_numeric($value)) {
            $parentId = (int) $value;

            if ($parentId <= 0) {
                return null;
            }

            if ($exceptId !== null && $parentId === $exceptId) {
                throw ValidationException::withMessages([
                    'rows' => ['A unit cannot be its own parent.'],
                ]);
            }

            $parentExists = self::query()
                ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
                ->where('id', $parentId)
                ->exists();

            if (! $parentExists) {
                throw ValidationException::withMessages([
                    'rows' => ["Parent unit with id {$parentId} was not found."],
                ]);
            }

            return $parentId;
        }

        $parent = self::query()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->whereRaw('LOWER(REPLACE(name, \' \', \'\')) = ?', [self::normalizeName((string) $value)])
            ->first();

        if ($parent === null) {
            throw ValidationException::withMessages([
                'rows' => ['Parent unit "'.(string) $value.'" was not found.'],
            ]);
        }

        if ($exceptId !== null && (int) $parent->id === $exceptId) {
            throw ValidationException::withMessages([
                'rows' => ['A unit cannot be its own parent.'],
            ]);
        }

        return (int) $parent->id;
    }

    protected static function normalizeImportBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
