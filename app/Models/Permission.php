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

    public static function CreatePermission($request, $menu, $status): self
    {
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
