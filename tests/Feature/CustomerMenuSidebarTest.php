<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('customer menu is visible in sidebar when not hidden', function () {
    $parentId = DB::table('menus')->insertGetId([
        'parent_id' => null,
        'name' => 'Contacts',
        'icon' => 'UserCircle',
        'route_name' => 'contacts',
        'route_path' => '',
        'menu_color' => '#000000',
        'sort_order' => 1,
        'is_hidden' => 0,
        'is_active' => 1,
        'is_permission' => 0,
        'type' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('menus')->insert([
        [
            'parent_id' => $parentId,
            'name' => 'Supplier',
            'icon' => 'Store',
            'route_name' => 'supplier',
            'route_path' => '/supplier',
            'menu_color' => '#000000',
            'sort_order' => 1,
            'is_hidden' => 0,
            'is_active' => 1,
            'is_permission' => 0,
            'type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'parent_id' => $parentId,
            'name' => 'Customer',
            'icon' => 'User',
            'route_name' => 'customer',
            'route_path' => '/customer',
            'menu_color' => '#000000',
            'sort_order' => 2,
            'is_hidden' => 0,
            'is_active' => 1,
            'is_permission' => 0,
            'type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $menus = Menu::sidebarMenusForRole(1);

    $contacts = collect($menus)->firstWhere('name', 'Contacts');

    expect($contacts)->not->toBeNull();

    $childNames = collect($contacts['children'] ?? [])->pluck('name');

    expect($childNames)->toContain('Supplier', 'Customer');
});

test('hidden customer menu is excluded from sidebar tree formatting', function () {
    $parentId = DB::table('menus')->insertGetId([
        'parent_id' => null,
        'name' => 'Contacts',
        'icon' => 'UserCircle',
        'route_name' => 'contacts',
        'route_path' => '',
        'menu_color' => '#000000',
        'sort_order' => 1,
        'is_hidden' => 0,
        'is_active' => 1,
        'is_permission' => 0,
        'type' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('menus')->insert([
        'parent_id' => $parentId,
        'name' => 'Customer',
        'icon' => 'User',
        'route_name' => 'customer',
        'route_path' => '/customer',
        'menu_color' => '#000000',
        'sort_order' => 2,
        'is_hidden' => 1,
        'is_active' => 1,
        'is_permission' => 0,
        'type' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customerMenu = collect(Menu::sidebarMenusForRole(1))
        ->firstWhere('name', 'Contacts');

    $child = collect($customerMenu['children'] ?? [])->firstWhere('name', 'Customer');

    expect($child)->not->toBeNull()
        ->and($child['is_hidden'])->toBeTrue();
});
