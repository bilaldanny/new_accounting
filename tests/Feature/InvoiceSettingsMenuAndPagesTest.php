<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The Invoice Settings menu migration and the Invoice Settings pages. The migration finds its group through a legacy page
 * row (`/business/settings`) that exists only in live data, so the tests seed that first.
 */
function ivsMigration(): object
{
    return require database_path('migrations/2026_09_21_240300_add_invoice_settings_menu.php');
}

/**
 * A menu group with one legacy page in it, the way the live data has it. Returns the group's id.
 */
function ivsSeedGroup(): int
{
    DB::table('menus')->where('route_path', '/business/settings')->delete();

    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $groupId = DB::table('menus')->insertGetId($row([
        'parent_id' => null, 'name' => 'Company Settings', 'route_name' => 'invoice/settings-test-group', 'route_path' => '#invoice/settings-test-group',
    ]));

    DB::table('menus')->insert($row([
        'parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => 'legacy-invoice/settings-anchor', 'route_path' => '/business/settings', 'sort_order' => 9,
    ]));

    return $groupId;
}

test('the menu migration adds the page in its group with the hidden permission rows', function () {
    $groupId = ivsSeedGroup();

    ivsMigration()->up();

    $page = DB::table('menus')->where('route_path', '/invoice/settings')->first();

    expect($page)->not->toBeNull()
        ->and((int) $page->parent_id)->toBe($groupId)
        ->and($page->icon)->toBe('Cog')
        ->and($page->route_name)->toBe('invoice.settings')
        ->and((int) $page->is_hidden)->toBe(0)
        ->and((int) $page->is_active)->toBe(1)
        ->and((int) $page->sort_order)->toBe(10);

    $hidden = DB::table('menus')->where('parent_id', $page->id)->orderBy('sort_order')->get();

    expect($hidden->pluck('route_path')->all())->toBe([
        '/invoice/settings/update',
    ])->and($hidden->every(fn (object $row): bool => (int) $row->is_hidden === 1 && (int) $row->is_active === 1))->toBeTrue();
});

test('the menu migration is idempotent and grants nothing to any role', function () {
    ivsSeedGroup();
    $roleId = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    ivsMigration()->up();
    ivsMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/invoice/settings%')->count())->toBe(2)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
});

test('the menu migration does nothing when the group cannot be found', function () {
    DB::table('menus')->where('route_path', '/business/settings')->delete();

    ivsMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/invoice/settings%')->count())->toBe(0);
});

test('rolling the menu migration back removes the rows and the permissions on them', function () {
    ivsSeedGroup();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    ivsMigration()->up();
    $addId = (int) DB::table('menus')->where('route_path', '/invoice/settings/update')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $addId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    ivsMigration()->down();

    expect(DB::table('menus')->where('route_path', 'like', '/invoice/settings%')->count())->toBe(0)
        ->and(DB::table('permissions')->where('menu_id', $addId)->count())->toBe(0)
        ->and(DB::table('menus')->where('route_path', '/business/settings')->exists())->toBeTrue();
});

test('the menu migration clears the cached menu permissions of the roles', function () {
    ivsSeedGroup();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    ivsMigration()->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
    }
});
