<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Role extends Model
{
    use SoftDeletes;

    public const HIDDEN_ROLE_NAME = 'companyadmin';

    protected $fillable = ['name', 'company_id', 'branch_id', 'is_active', 'is_hide'];

    protected function IsActive(): Attribute
    {
        return Attribute::make(
            // Getter: Return true if the value is 1
            get: fn ($value) => $value === 1,

            // Setter: Convert true/false to 1/0
            set: fn ($value) => ($value === 'false') ? 0 : 1,
        );
    }

    protected function IsHide(): Attribute
    {
        return Attribute::make(
            // Getter: Return true if the value is 1
            get: fn ($value) => $value === 1,

            // Setter: Convert true/false to 1/0
            set: fn ($value) => ($value === 'false') ? 0 : 1,
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

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

    public function isHiddenFromCurrentUser(): bool
    {
        return self::normalizeName($this->name) === self::normalizeName(self::HIDDEN_ROLE_NAME)
            && ! Auth::user()?->hasRole('superadmin');
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        if (Auth::user()?->hasRole('superadmin')) {
            return $query;
        }

        return $query->whereRaw("LOWER(REPLACE(name, ' ', '')) != ?", [self::normalizeName(self::HIDDEN_ROLE_NAME)]);
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
                'name' => ['A role with this name already exists.'],
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

    public static function CreateRole($request)
    {
        $companyId = self::resolveScopedId($request->company_id);
        $branchId = self::resolveScopedId($request->branch_id);

        self::assertUniqueName($request->name, null, $companyId, $branchId);

        $role = new Role;
        $role->company_id = $companyId;
        $role->branch_id = $branchId;
        $role->name = $request->name;
        $role->is_active = $request->is_active;
        $role->save();

        return $role;
    }

    public static function UpdateRole($request, $id)
    {
        $role = self::findVisibleToCurrentUser((int) $id);

        if ($role === null) {
            abort(404);
        }

        $companyId = self::resolveScopedId($request->company_id);
        $branchId = self::resolveScopedId($request->branch_id);

        self::assertUniqueName($request->name, (int) $id, $companyId, $branchId);

        $role->company_id = $companyId;
        $role->branch_id = $branchId;
        $role->name = $request->name;
        $role->is_active = $request->is_active;
        $role->save();

        return $role;
    }

    public static function DeleteRole($id)
    {
        $role = Role::find($id);
        if (isset($role)) {
            $role->delete();
        }
    }
}
