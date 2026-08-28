<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function grantMenuPermission(int $roleId, string $routePath, string $routeName = ''): int
{
    $menuId = DB::table('menus')->insertGetId([
        'parent_id' => null,
        'name' => $routePath,
        'icon' => 'Grid',
        'route_name' => $routeName !== '' ? $routeName : ltrim($routePath, '/'),
        'route_path' => $routePath,
        'menu_color' => '#000000',
        'sort_order' => 1,
        'is_hidden' => 0,
        'is_active' => 1,
        'is_permission' => 1,
        'type' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Permission::query()->create([
        'role_id' => $roleId,
        'menu_id' => $menuId,
        'status' => 1,
    ]);

    Cache::forget("user_permission_paths:{$roleId}");
    Cache::forget("user_menu_permissions:{$roleId}");
    Cache::forget("user_menu_permissions_tree:{$roleId}");

    return $menuId;
}

function createStaffUserForRole(Role $role, array $attributes = []): User
{
    return User::query()->create(array_merge([
        'role_id' => $role->id,
        'first_name' => 'Staff',
        'last_name' => 'User',
        'username' => 'staff_'.$role->id.'_'.uniqid(),
        'email' => 'staff_'.$role->id.'_'.uniqid().'@example.com',
        'password' => Hash::make('password'),
        'is_active' => true,
    ], $attributes));
}
