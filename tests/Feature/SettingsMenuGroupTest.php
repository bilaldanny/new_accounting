<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Menus, Currencies and Timezones exist in live data but are not created by any migration, so a
 * fresh schema only has the last four. These helpers recreate the live rows for the full-set tests.
 */
function seedLegacySettingsRoots(): void
{
    foreach ([
        ['Menus', '/menu', 1],
        ['Currencies', '/currency', 1],
        ['Timezones', '/timezone', 1],
    ] as [$name, $path, $sort]) {
        $id = DB::table('menus')->insertGetId([
            'parent_id' => null,
            'name' => $name,
            'icon' => 'Cog',
            'route_name' => $path,
            'route_path' => $path,
            'menu_color' => '#199683',
            'sort_order' => $sort,
            'is_hidden' => 0,
            'is_active' => 1,
            'is_admin' => 1,
            'is_permission' => 1,
            'type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('menus')->insert([
            'parent_id' => $id,
            'name' => 'Add '.$name,
            'icon' => 'Grid',
            'route_name' => 'add'.strtolower($name),
            'route_path' => $path.'/add',
            'menu_color' => '#199683',
            'sort_order' => 1,
            'is_hidden' => 1,
            'is_active' => 1,
            'is_admin' => 1,
            'is_permission' => 1,
            'type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function rerunSettingsGroupMigration(): void
{
    $migration = require database_path('migrations/2026_09_19_000000_group_settings_menus.php');
    $migration->up();
}

function settingsParentId(): int
{
    return (int) DB::table('menus')->whereNull('parent_id')->where('name', 'Settings')->where('type', 2)->value('id');
}

test('a fresh schema groups software setting, country, state and city under the settings parent', function () {
    $parent = DB::table('menus')->whereNull('parent_id')->where('name', 'Settings')->where('type', 2)->first();

    expect($parent)->not->toBeNull()
        ->and((int) $parent->is_hidden)->toBe(0)
        ->and((int) $parent->is_active)->toBe(1)
        ->and((int) $parent->is_permission)->toBe(1);

    expect(DB::table('menus')->where('parent_id', $parent->id)->orderBy('sort_order')->pluck('route_path')->all())
        ->toBe(['/software/setting', '/country', '/state', '/city', '/backup']);

    foreach (['/software/setting', '/country', '/state', '/city'] as $path) {
        expect(DB::table('menus')->whereNull('parent_id')->where('route_path', $path)->exists())->toBeFalse();
    }
});

test('with the live-only roots present all seven menus move under settings in the requested order', function () {
    seedLegacySettingsRoots();
    rerunSettingsGroupMigration();

    expect(DB::table('menus')->where('parent_id', settingsParentId())->orderBy('sort_order')->pluck('route_path')->all())
        // '/backup' (Database Backup, 2026_09_21_250100) is the newest row in the group
        ->toBe(['/menu', '/currency', '/timezone', '/software/setting', '/country', '/state', '/city', '/backup']);

    expect(DB::table('menus')->where('name', 'Settings')->where('type', 2)->count())->toBe(1);
});

test('moved menus keep their hidden permission rows attached', function () {
    seedLegacySettingsRoots();
    rerunSettingsGroupMigration();

    $menuId = DB::table('menus')->where('route_path', '/menu')->value('id');
    $countryId = DB::table('menus')->where('route_path', '/country')->value('id');

    expect(DB::table('menus')->where('parent_id', $menuId)->where('route_path', '/menu/add')->exists())->toBeTrue()
        ->and(DB::table('menus')->where('parent_id', $countryId)->where('route_path', '/country/add')->exists())->toBeTrue();
});

test('the superadmin sidebar renders settings as a dropdown with all seven links', function () {
    seedLegacySettingsRoots();
    rerunSettingsGroupMigration();

    $settings = collect(Menu::sidebarMenusForRole(1))->firstWhere('name', 'Settings');

    expect($settings)->not->toBeNull()
        ->and((int) $settings['type'])->toBe(2)
        ->and(collect($settings['children'])->pluck('my_route')->all())
        ->toBe(['/menu', '/currency', '/timezone', '/software/setting', '/country', '/state', '/city', '/backup']);
});

test('a role granted only country still sees the settings parent in its sidebar', function () {
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'settingsviewer',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('permissions')->insert([
        'role_id' => $roleId,
        'menu_id' => DB::table('menus')->where('route_path', '/country')->value('id'),
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $settings = collect(Menu::sidebarMenusForRole($roleId))->firstWhere('name', 'Settings');

    expect($settings)->not->toBeNull()
        ->and(collect($settings['children'])->pluck('my_route')->all())->toBe(['/country']);
});
