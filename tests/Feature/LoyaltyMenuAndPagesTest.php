<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The Loyalty Points menu migration and the Loyalty Points pages. The migration finds its group through a legacy page
 * row (`/sell`) that exists only in live data, so the tests seed that first.
 */
function loymMigration(): object
{
    return require database_path('migrations/2026_09_21_220100_add_loyalty_menu.php');
}

/**
 * A menu group with one legacy page in it, the way the live data has it. Returns the group's id.
 */
function loymSeedGroup(): int
{
    DB::table('menus')->where('route_path', '/sell')->delete();

    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $groupId = DB::table('menus')->insertGetId($row([
        'parent_id' => null, 'name' => 'Sell', 'route_name' => 'loyalty-test-group', 'route_path' => '#loyalty-test-group',
    ]));

    DB::table('menus')->insert($row([
        'parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => 'legacy-loyalty-anchor', 'route_path' => '/sell', 'sort_order' => 9,
    ]));

    return $groupId;
}

test('the menu migration adds the page in its group with the hidden permission rows', function () {
    $groupId = loymSeedGroup();

    loymMigration()->up();

    $page = DB::table('menus')->where('route_path', '/loyalty')->first();

    expect($page)->not->toBeNull()
        ->and((int) $page->parent_id)->toBe($groupId)
        ->and($page->icon)->toBe('Medal')
        ->and($page->route_name)->toBe('loyalty')
        ->and((int) $page->is_hidden)->toBe(0)
        ->and((int) $page->is_active)->toBe(1)
        ->and((int) $page->sort_order)->toBe(10);

    $hidden = DB::table('menus')->where('parent_id', $page->id)->orderBy('sort_order')->get();

    expect($hidden->pluck('route_path')->all())->toBe([
        '/loyalty/:id/view',
        '/loyalty/settings',
        '/loyalty/earn',
        '/loyalty/redeem',
        '/loyalty/adjust',
        '/loyalty/export',
    ])->and($hidden->every(fn (object $row): bool => (int) $row->is_hidden === 1 && (int) $row->is_active === 1))->toBeTrue();
});

test('the menu migration is idempotent and grants nothing to any role', function () {
    loymSeedGroup();
    $roleId = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    loymMigration()->up();
    loymMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/loyalty%')->count())->toBe(7)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
});

test('the menu migration does nothing when the group cannot be found', function () {
    DB::table('menus')->where('route_path', '/sell')->delete();

    loymMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/loyalty%')->count())->toBe(0);
});

test('rolling the menu migration back removes the rows and the permissions on them', function () {
    loymSeedGroup();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    loymMigration()->up();
    $addId = (int) DB::table('menus')->where('route_path', '/loyalty/:id/view')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $addId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    loymMigration()->down();

    expect(DB::table('menus')->where('route_path', 'like', '/loyalty%')->count())->toBe(0)
        ->and(DB::table('permissions')->where('menu_id', $addId)->count())->toBe(0)
        ->and(DB::table('menus')->where('route_path', '/sell')->exists())->toBeTrue();
});

test('the menu migration clears the cached menu permissions of the roles', function () {
    loymSeedGroup();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    loymMigration()->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
    }
});
