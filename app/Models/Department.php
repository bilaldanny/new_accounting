<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public static function createDepartment($request): self
    {
        $department = new self;
        $department->company_id = $request->company_id ?: null;
        $department->branch_id = $request->branch_id ?: null;
        $department->name = $request->name;
        $department->active = $request->active ?? true;
        $department->created_by = auth()->id();
        $department->updated_by = auth()->id();
        $department->save();

        return $department;
    }

    public static function updateDepartment($request, $id): self
    {
        $department = self::findOrFail($id);
        $department->company_id = $request->company_id ?: null;
        $department->branch_id = $request->branch_id ?: null;
        $department->name = $request->name;
        $department->active = $request->active ?? true;
        $department->updated_by = auth()->id();
        $department->save();

        return $department;
    }

    public static function deleteDepartment($id): void
    {
        $department = self::find($id);

        if ($department !== null) {
            $department->delete();
        }
    }
}
