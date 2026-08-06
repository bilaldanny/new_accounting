<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'is_active', 'is_hide'];

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

    public static function CreateRole($request)
    {
        $role = new Role;
        $role->name = $request->name;
        $role->is_active = $request->is_active;
        $role->save();

        return $role;
    }

    public static function UpdateRole($request, $id)
    {
        $role = Role::find($id);
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
