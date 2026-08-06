<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Menu extends Model
{
    use SoftDeletes;

    protected $fillable = ['parent_id', 'name', 'icon', 'route_name', 'route_path', 'menu_color', 'sort_order', 'type', 'is_active', 'is_hidden', 'is_permission'];

    protected function isActive(): Attribute
    {
        return Attribute::make(
            // Getter: Return true if the value is 1
            get: fn ($value) => $value === 1,

            // Setter: Convert true/false to 1/0
            set: fn ($value) => ($value === 'false') ? 0 : 1,
        );
    }

    protected function isHidden(): Attribute
    {
        return Attribute::make(
            // Getter: Return true if the value is 1
            get: fn ($value) => $value === 1,

            // Setter: Convert true/false to 1/0
            set: fn ($value) => ($value === 'false') ? 0 : 1,
        );
    }

    protected function isPermission(): Attribute
    {
        return Attribute::make(
            // Getter: Return true if the value is 1
            get: fn ($value) => $value === 1,

            // Setter: Convert true/false to 1/0
            set: fn ($value) => ($value === 'false') ? 0 : 1,
        );
    }

    protected function parentId(): Attribute
    {
        return Attribute::make(
            // Getter: Return an empty string if the value is null
            get: fn ($value) => $value === null ? '' : $value
        );
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'id')
            ->whereColumn('menus.id', '!=', 'menus.parent_id');
    }

    public function permission()
    {
        return $this->hasMany(Permission::class, 'menu_id', 'id')->where('role_id', '=', auth()->user()->role_id);
    }

    public static function CreateMenu($request)
    {
        $menu = new Menu;
        $menu->parent_id = $request->parent_id;
        $menu->name = $request->name;
        $menu->route_name = strtolower(str_replace(' ', '', $request->route_name));
        $menu->icon = $request->icon;
        $menu->route_path = $request->route_path;
        $menu->menu_color = ($request->menu_color) ?? '#6a0dad';
        if (isset($request->sort_order)) {
            $menu->sort_order = $request->sort_order;
        } else {
            $menu->sort_order = 0;
        }
        $menu->type = $request->type;
        $menu->is_active = $request->is_active;
        $menu->is_hidden = $request->is_hidden;
        $menu->is_permission = $request->is_permission;
        $menu->save();

        return $menu;
    }

    public static function UpdateMenu($request, $id)
    {
        $menu = Menu::find($id);
        $menu->parent_id = ($request->parent_id) ?? null;
        $menu->name = $request->name;
        $menu->route_name = strtolower(str_replace(' ', '', $request->route_name));
        $menu->icon = $request->icon;
        $menu->route_path = $request->route_path;
        $menu->menu_color = ($request->menu_color) ?? '#6a0dad';
        if (isset($request->sort_order)) {
            $menu->sort_order = $request->sort_order;
        } else {
            $menu->sort_order = 0;
        }
        $menu->type = $request->type;
        $menu->is_active = $request->is_active;
        $menu->is_hidden = $request->is_hidden;
        $menu->is_permission = $request->is_permission;
        $menu->save();

        return $menu;
    }

    public static function DeleteMenu($id)
    {
        $menu = Menu::find($id);
        if (isset($menu)) {
            $menu->delete();
        }
    }
}
