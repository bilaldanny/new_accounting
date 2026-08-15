<?php

use App\Models\Menu;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('sidebar menus include ancestor items for permitted role menus', function () {
    $parentMenuId = DB::table('menus')->insertGetId([
        'parent_id' => null,
        'name' => 'Operations',
        'icon' => 'Grid',
        'route_name' => 'operations',
        'route_path' => '/operations',
        'menu_color' => '#000000',
        'sort_order' => 1,
        'is_hidden' => 0,
        'is_active' => 1,
        'is_permission' => 1,
        'type' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $childMenuId = DB::table('menus')->insertGetId([
        'parent_id' => $parentMenuId,
        'name' => 'Branch List',
        'icon' => 'Grid',
        'route_name' => 'branch',
        'route_path' => '/branch',
        'menu_color' => '#000000',
        'sort_order' => 1,
        'is_hidden' => 0,
        'is_active' => 1,
        'is_permission' => 1,
        'type' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $roleId = DB::table('roles')->insertGetId([
        'name' => 'branchmanager',
        'is_active' => 1,
        'is_admin' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Permission::query()->create([
        'role_id' => $roleId,
        'menu_id' => $childMenuId,
        'status' => 1,
    ]);

    $menus = Menu::sidebarMenusForRole($roleId);

    expect($menus)->toHaveCount(1)
        ->and($menus[0]['name'])->toBe('Operations')
        ->and($menus[0]['children'][0]['name'])->toBe('Branch List');

    expect(Menu::permittedRoutePathsForRole($roleId))->toContain('/branch');

    Cache::forget("user_menu_permissions_tree:{$roleId}");
    Cache::forget("user_permission_paths:{$roleId}");

    $user = User::query()->forceCreate([
        'first_name' => 'Branch',
        'last_name' => 'Manager',
        'username' => 'branchmanager',
        'email' => 'branchmanager@example.com',
        'password' => bcrypt('password'),
        'pass' => 'password',
        'role_id' => $roleId,
        'is_active' => true,
    ]);

    Auth::login($user);

    expect($user->getPermissions())->toHaveCount(1);
});
