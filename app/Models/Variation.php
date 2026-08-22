<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Variation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'category_id',
        'subcategory_id',
        'itemtype_id',
        'values',
        'priority',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'values' => 'array',
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

    protected function categoryId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function subcategoryId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function itemtypeId(): Attribute
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
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    /**
     * @return BelongsTo<ItemType, $this>
     */
    public function itemtype(): BelongsTo
    {
        return $this->belongsTo(ItemType::class, 'itemtype_id');
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

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<int, array{name?: string, active?: bool}>|null  $values
     * @return array<int, array{name: string, active: bool}>
     */
    public static function normalizeValues(?array $values): array
    {
        if ($values === null || $values === []) {
            return [];
        }

        return collect($values)
            ->map(function ($value) {
                if (! is_array($value)) {
                    return ['name' => '', 'active' => true];
                }

                return [
                    'name' => trim((string) ($value['name'] ?? '')),
                    'active' => ! ($value['active'] === 'false' || $value['active'] === false || $value['active'] === 0 || $value['active'] === '0'),
                ];
            })
            ->filter(fn (array $value) => $value['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public static function assertValidValues(?array $values): void
    {
        $normalized = self::normalizeValues($values);

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'values' => ['Add at least one variation value.'],
            ]);
        }
    }

    public static function createVariation(object $request): self
    {
        self::assertValidValues($request->values ?? null);

        $variation = new self;
        $variation->company_id = self::resolveScopedId($request->company_id);
        $variation->category_id = self::resolveScopedId($request->category_id);
        $variation->subcategory_id = self::resolveScopedId($request->subcategory_id);
        $variation->itemtype_id = self::resolveScopedId($request->itemtype_id);
        $variation->values = self::normalizeValues($request->values ?? null);
        $variation->priority = (int) ($request->priority ?? 0);
        $variation->active = $request->active ?? true;
        $variation->save();

        return $variation;
    }

    public static function updateVariation(object $request, int $id): self
    {
        $variation = self::findVisibleToCurrentUser($id);

        if ($variation === null) {
            abort(404);
        }

        self::assertValidValues($request->values ?? null);

        $variation->company_id = self::resolveScopedId($request->company_id);
        $variation->category_id = self::resolveScopedId($request->category_id);
        $variation->subcategory_id = self::resolveScopedId($request->subcategory_id);
        $variation->itemtype_id = self::resolveScopedId($request->itemtype_id);
        $variation->values = self::normalizeValues($request->values ?? null);
        $variation->priority = (int) ($request->priority ?? 0);
        $variation->active = $request->active ?? true;
        $variation->save();

        return $variation;
    }

    public static function deleteVariation(int $id): void
    {
        $variation = self::findVisibleToCurrentUser($id);

        if ($variation === null) {
            return;
        }

        $variation->delete();
    }

    /**
     * @param  array<int, array{name?: string, active?: bool}>|null  $values
     */
    public static function valuesDisplay(?array $values): string
    {
        $normalized = self::normalizeValues($values);

        if ($normalized === []) {
            return '-';
        }

        return collect($normalized)
            ->pluck('name')
            ->filter()
            ->implode(', ');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function upsertFromImport(array $row): string
    {
        $id = self::normalizeImportId($row['id'] ?? null);
        $payload = self::buildImportPayload($row, $id);

        if ($id !== null) {
            $variation = self::findVisibleToCurrentUser($id);

            if ($variation === null) {
                throw ValidationException::withMessages([
                    'rows' => ["Variation with id {$id} was not found."],
                ]);
            }

            self::updateVariation($payload, $id);

            return 'updated';
        }

        self::createVariation($payload);

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
            $existingVariation = self::findVisibleToCurrentUser($existingId);

            if ($existingVariation !== null && $companyId === null) {
                $companyId = self::resolveScopedId($existingVariation->company_id);
            }
        }

        $categoryId = self::resolveImportCategoryId(
            $row['category_id'] ?? $row['category'] ?? null,
            $companyId,
        );

        $subcategoryId = self::resolveImportSubcategoryId(
            $row['subcategory_id'] ?? $row['subcategory'] ?? null,
            $companyId,
            $categoryId,
        );

        $active = $row['active'] ?? $row['is_active'] ?? 1;

        return (object) [
            'company_id' => $companyId,
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
            'itemtype_id' => self::resolveImportItemTypeId(
                $row['itemtype_id'] ?? $row['item_type'] ?? $row['itemtype'] ?? null,
                $companyId,
            ),
            'values' => self::parseImportValues($row['values'] ?? null),
            'priority' => (int) ($row['priority'] ?? 0),
            'active' => self::normalizeImportBool($active),
        ];
    }

    public static function resolveImportCategoryId(mixed $value, ?int $companyId): int
    {
        $categoryId = self::resolveImportScopedRecordId(
            $value,
            $companyId,
            Category::class,
            'Category',
        );

        if ($categoryId === null) {
            throw ValidationException::withMessages([
                'rows' => ['Category is required and must match an existing category name or id.'],
            ]);
        }

        return $categoryId;
    }

    public static function resolveImportSubcategoryId(
        mixed $value,
        ?int $companyId,
        int $categoryId,
    ): ?int {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        $subcategoryId = self::resolveImportScopedRecordId(
            $value,
            $companyId,
            Category::class,
            'Subcategory',
        );

        if ($subcategoryId === null) {
            throw ValidationException::withMessages([
                'rows' => ['Subcategory "'.(string) $value.'" was not found.'],
            ]);
        }

        $subcategory = Category::query()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->where('id', $subcategoryId)
            ->first();

        if ($subcategory === null || (int) $subcategory->parent_id !== $categoryId) {
            throw ValidationException::withMessages([
                'rows' => ['Subcategory "'.(string) $value.'" does not belong to the selected category.'],
            ]);
        }

        return $subcategoryId;
    }

    public static function resolveImportItemTypeId(mixed $value, ?int $companyId): int
    {
        $itemTypeId = self::resolveImportScopedRecordId(
            $value,
            $companyId,
            ItemType::class,
            'Item type',
        );

        if ($itemTypeId === null) {
            throw ValidationException::withMessages([
                'rows' => ['Item type is required and must match an existing item type name or id.'],
            ]);
        }

        return $itemTypeId;
    }

    /**
     * @param  class-string<Category|ItemType>  $modelClass
     */
    protected static function resolveImportScopedRecordId(
        mixed $value,
        ?int $companyId,
        string $modelClass,
        string $label,
    ): ?int {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        if (is_numeric($value)) {
            $recordId = (int) $value;

            if ($recordId <= 0) {
                return null;
            }

            $exists = $modelClass::query()
                ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
                ->where('id', $recordId)
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'rows' => ["{$label} with id {$recordId} was not found."],
                ]);
            }

            return $recordId;
        }

        /** @var Category|ItemType|null $record */
        $record = $modelClass::query()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->whereRaw('LOWER(REPLACE(name, \' \', \'\')) = ?', [$modelClass::normalizeName((string) $value)])
            ->first();

        if ($record === null) {
            throw ValidationException::withMessages([
                'rows' => ["{$label} \"{$value}\" was not found."],
            ]);
        }

        return (int) $record->id;
    }

    /**
     * @return array<int, array{name: string, active: bool}>
     */
    public static function parseImportValues(mixed $value): array
    {
        if (is_array($value)) {
            return self::normalizeValues($value);
        }

        $text = trim((string) $value);

        if ($text === '') {
            throw ValidationException::withMessages([
                'rows' => ['At least one variation value is required. Use pipe-separated names (e.g. Small|Medium|Large).'],
            ]);
        }

        if (str_starts_with($text, '[')) {
            $decoded = json_decode($text, true);

            if (is_array($decoded)) {
                return self::normalizeValues($decoded);
            }
        }

        $names = collect(explode('|', $text))
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->values()
            ->all();

        return self::normalizeValues(array_map(
            fn (string $name) => ['name' => $name, 'active' => true],
            $names,
        ));
    }

    protected static function normalizeImportBool(mixed $value): bool
    {
        return self::normalizeRequestBool($value);
    }

    public static function normalizeRequestBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'on', 'yes', 'active'], true);
        }

        return (bool) $value;
    }
}
