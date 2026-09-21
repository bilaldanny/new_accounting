<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'department_id',
        'role_id',
        'menu_id',
        'status',
    ];

    public function role()
    {
        return $this->hasOne(Role::class, 'id', 'role_id');
    }

    public function company()
    {
        return $this->hasOne(Company::class, 'id', 'company_id');
    }

    /**
     * Records a role's permission on a menu in a scope. There is no unique key on the table, so a row that
     * already exists for the same role, menu, company, branch and department is set to the status instead of a
     * second one being added (a duplicate would otherwise pile up, e.g. when two saves race).
     */
    public static function CreatePermission($request, $menu, $status): self
    {
        $scope = [
            'company_id' => $request->company_id ?: null,
            'branch_id' => $request->branch_id ?: null,
            'department_id' => $request->department_id ?: null,
        ];

        $existing = self::query()->where('role_id', $request->role_id)->where('menu_id', $menu);

        foreach ($scope as $column => $value) {
            $value === null ? $existing->whereNull($column) : $existing->where($column, $value);
        }

        $found = $existing->orderBy('id')->first();

        if ($found !== null) {
            $found->status = $status;
            $found->save();

            return $found;
        }

        $permission = new self;
        $permission->company_id = $request->company_id ?: null;
        $permission->branch_id = $request->branch_id ?: null;
        $permission->department_id = $request->department_id ?: null;
        $permission->role_id = $request->role_id;
        $permission->menu_id = $menu;
        $permission->status = $status;
        $permission->save();

        return $permission;
    }

    public static function UpdatePermission($permission, $status): void
    {
        $record = self::find($permission->id);

        if ($record === null) {
            return;
        }

        $record->company_id = $permission->company_id;
        $record->branch_id = $permission->branch_id;
        $record->department_id = $permission->department_id;
        $record->role_id = $permission->role_id;
        $record->menu_id = $permission->menu_id;
        $record->status = $status;
        $record->save();
    }
}
