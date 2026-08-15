<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'active',
        'created_by',
        'updated_by',
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

    protected function branchId(): Attribute
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

    public static function normalizeName(?string $name): string
    {
        return strtolower(preg_replace('/\s+/', '', trim((string) $name)));
    }

    public static function nameExists(
        string $name,
        ?int $exceptId = null,
        ?int $companyId = null,
        ?int $branchId = null,
    ): bool {
        return self::query()
            ->when($exceptId !== null, fn (Builder $query) => $query->where('id', '!=', $exceptId))
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->whereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [self::normalizeName($name)])
            ->exists();
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
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
        ?int $branchId = null,
    ): void {
        if (self::nameExists($name, $exceptId, $companyId, $branchId)) {
            throw ValidationException::withMessages([
                'name' => ['A department with this name already exists.'],
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

    public static function createDepartment($request): self
    {
        $companyId = self::resolveScopedId($request->company_id);
        $branchId = self::resolveScopedId($request->branch_id);

        self::assertUniqueName($request->name, null, $companyId, $branchId);

        $department = new self;
        $department->company_id = $companyId;
        $department->branch_id = $branchId;
        $department->name = $request->name;
        $department->active = $request->active ?? true;
        $department->created_by = auth()->id();
        $department->updated_by = auth()->id();
        $department->save();

        return $department;
    }

    public static function updateDepartment($request, $id): self
    {
        $department = self::findVisibleToCurrentUser((int) $id);

        if ($department === null) {
            abort(404);
        }

        $companyId = self::resolveScopedId($request->company_id);
        $branchId = self::resolveScopedId($request->branch_id);

        self::assertUniqueName($request->name, (int) $id, $companyId, $branchId);

        $department->company_id = $companyId;
        $department->branch_id = $branchId;
        $department->name = $request->name;
        $department->active = $request->active ?? true;
        $department->updated_by = auth()->id();
        $department->save();

        return $department;
    }

    public static function deleteDepartment($id): void
    {
        $department = self::findVisibleToCurrentUser((int) $id);

        if ($department !== null) {
            $department->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function upsertFromImport(array $row): string
    {
        $id = self::normalizeImportId($row['id'] ?? null);
        $payload = self::buildImportPayload($row);

        if ($id !== null) {
            $department = self::findVisibleToCurrentUser($id);

            if ($department === null) {
                throw ValidationException::withMessages([
                    'rows' => ["Department with id {$id} was not found."],
                ]);
            }

            self::updateDepartment($payload, $id);

            return 'updated';
        }

        self::createDepartment($payload);

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
    protected static function buildImportPayload(array $row): object
    {
        $active = $row['active'] ?? $row['is_active'] ?? 1;

        return (object) [
            'company_id' => self::resolveScopedId($row['company_id'] ?? null),
            'branch_id' => self::resolveScopedId($row['branch_id'] ?? null),
            'name' => (string) ($row['name'] ?? ''),
            'active' => self::normalizeImportBool($active),
        ];
    }

    protected static function normalizeImportBool(mixed $value): int
    {
        return $value === true || $value === 1 || $value === '1' ? 1 : 0;
    }
}
