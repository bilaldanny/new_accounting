<?php

namespace App\Models;

use Database\Factories\CustomerGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class CustomerGroup extends Model
{
    /** @use HasFactory<CustomerGroupFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'price_calculation_type',
        'calculation_percentage',
        'active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'calculation_percentage' => 'decimal:2',
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
                'name' => ['A customer group with this name already exists.'],
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

    public static function createCustomerGroup(object $request): self
    {
        $companyId = self::resolveScopedId($request->company_id);
        $branchId = self::resolveScopedId($request->branch_id);

        self::assertUniqueName($request->name, null, $companyId, $branchId);

        $customerGroup = new self;
        $customerGroup->company_id = $companyId;
        $customerGroup->branch_id = $branchId;
        $customerGroup->name = $request->name;
        $customerGroup->price_calculation_type = $request->price_calculation_type ?? 'percentage';
        $customerGroup->calculation_percentage = $request->calculation_percentage ?? 0;
        $customerGroup->active = $request->active ?? true;
        $customerGroup->created_by = auth()->id();
        $customerGroup->updated_by = auth()->id();
        $customerGroup->save();

        return $customerGroup;
    }

    public static function updateCustomerGroup(object $request, int $id): self
    {
        $customerGroup = self::findVisibleToCurrentUser($id);

        if ($customerGroup === null) {
            abort(404);
        }

        $companyId = self::resolveScopedId($request->company_id);
        $branchId = self::resolveScopedId($request->branch_id);

        self::assertUniqueName($request->name, $id, $companyId, $branchId);

        $customerGroup->company_id = $companyId;
        $customerGroup->branch_id = $branchId;
        $customerGroup->name = $request->name;
        $customerGroup->price_calculation_type = $request->price_calculation_type ?? 'percentage';
        $customerGroup->calculation_percentage = $request->calculation_percentage ?? 0;
        $customerGroup->active = $request->active ?? true;
        $customerGroup->updated_by = auth()->id();
        $customerGroup->save();

        return $customerGroup;
    }

    public static function deleteCustomerGroup(int $id): void
    {
        $customerGroup = self::findVisibleToCurrentUser($id);

        if ($customerGroup !== null) {
            $customerGroup->delete();
        }
    }
}
