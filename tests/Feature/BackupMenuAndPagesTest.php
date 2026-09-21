<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The Database Backup menu migration and the Database Backup pages. The migration finds its group through a legacy page
 * row (`/software/setting`) that exists only in live data, so the tests seed that first.
 */
function bkmMigration(): object
{
    return require database_path('migrations/2026_09_21_250100_add_backup_menu.php');
}

/**
 * A menu group with one legacy page in it, the way the live data has it. Returns the group's id.
 */
function bkmSeedGroup(): int
{
    // the fresh test schema already has the anchor and the rows this migration adds: start clean
    DB::table('menus')->where('route_path', 'like', '/backup%')->delete();
    DB::table('menus')->where('route_path', '/software/setting')->delete();

    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $groupId = DB::table('menus')->insertGetId($row([
        'parent_id' => null, 'name' => 'Settings', 'route_name' => 'backup-test-group', 'route_path' => '#backup-test-group',
    ]));

    DB::table('menus')->insert($row([
        'parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => 'legacy-backup-anchor', 'route_path' => '/software/setting', 'sort_order' => 9,
    ]));

    return $groupId;
}

test('the menu migration adds the page in its group with the hidden permission rows', function () {
    $groupId = bkmSeedGroup();

    bkmMigration()->up();

    $page = DB::table('menus')->where('route_path', '/backup')->first();

    expect($page)->not->toBeNull()
        ->and((int) $page->parent_id)->toBe($groupId)
        ->and($page->icon)->toBe('Archive')
        ->and($page->route_name)->toBe('backup')
        ->and((int) $page->is_hidden)->toBe(0)
        ->and((int) $page->is_active)->toBe(1)
        ->and((int) $page->sort_order)->toBe(10);

    $hidden = DB::table('menus')->where('parent_id', $page->id)->orderBy('sort_order')->get();

    expect($hidden->pluck('route_path')->all())->toBe([
        '/backup/create',
        '/backup/download',
        '/backup/delete',
    ])->and($hidden->every(fn (object $row): bool => (int) $row->is_hidden === 1 && (int) $row->is_active === 1))->toBeTrue();
});

test('the menu migration is idempotent and grants nothing to any role', function () {
    bkmSeedGroup();
    $roleId = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    bkmMigration()->up();
    bkmMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/backup%')->count())->toBe(4)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
});

test('the menu migration does nothing when the group cannot be found', function () {
    DB::table('menus')->where('route_path', 'like', '/backup%')->delete();
    DB::table('menus')->where('route_path', '/software/setting')->delete();

    bkmMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/backup%')->count())->toBe(0);
});

test('rolling the menu migration back removes the rows and the permissions on them', function () {
    bkmSeedGroup();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    bkmMigration()->up();
    $addId = (int) DB::table('menus')->where('route_path', '/backup/create')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $addId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    bkmMigration()->down();

    expect(DB::table('menus')->where('route_path', 'like', '/backup%')->count())->toBe(0)
        ->and(DB::table('permissions')->where('menu_id', $addId)->count())->toBe(0)
        ->and(DB::table('menus')->where('route_path', '/software/setting')->exists())->toBeTrue();
});

test('the menu migration clears the cached menu permissions of the roles', function () {
    bkmSeedGroup();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    bkmMigration()->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
    }
});
