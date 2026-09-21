<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The two hidden Cash Collection rows (Reverse and Advance) and their companyadmin grant. The rows hang under
 * the Cash Collection page, which itself comes from a legacy anchor (`/sell`) that exists only in live data.
 */
function ccmMenuMigration(): object
{
    return require database_path('migrations/2026_09_23_110100_add_cashcollection_reverse_and_advance_menus.php');
}

function ccmGrantMigration(): object
{
    return require database_path('migrations/2026_09_23_110200_grant_companyadmin_cashcollection_reverse_advance_menus.php');
}

function ccmSeedCashCollectionPage(): int
{
    DB::table('menus')->where('route_path', '/sell')->delete();
    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];
    $groupId = DB::table('menus')->insertGetId($row(['parent_id' => null, 'name' => 'Sell', 'route_name' => 'ccm-group', 'route_path' => '#ccm-group']));
    DB::table('menus')->insert($row(['parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => 'legacy-ccm-anchor', 'route_path' => '/sell']));
    (require database_path('migrations/2026_09_21_260100_add_cashcollection_menu.php'))->up();

    return (int) DB::table('menus')->where('route_path', '/cashcollection')->value('id');
}

test('the menu migration adds the two hidden rows under the Cash Collection page, once', function () {
    $pageId = ccmSeedCashCollectionPage();

    ccmMenuMigration()->up();
    ccmMenuMigration()->up();

    $rows = DB::table('menus')->whereIn('route_path', ['/cashcollection/reverse', '/cashcollection/advance'])->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('route_path')->all())->toBe(['/cashcollection/reverse', '/cashcollection/advance'])
        ->and($rows->every(fn (object $row): bool => (int) $row->parent_id === $pageId && (int) $row->is_hidden === 1 && (int) $row->is_active === 1))->toBeTrue()
        ->and(DB::table('menus')->where('route_path', 'like', '/cashcollection%')->count())->toBe(11);
});

test('the menu migration does nothing without the Cash Collection page and grants nothing', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);

    ccmMenuMigration()->up();
    expect(DB::table('menus')->where('route_path', 'like', '/cashcollection%')->count())->toBe(0);

    ccmSeedCashCollectionPage();
    ccmMenuMigration()->up();

    expect(DB::table('permissions')->where('role_id', $role->id)->count())->toBe(0);
});

test('the grant gives the global companyadmin role exactly the two rows and leaves others alone', function () {
    ccmSeedCashCollectionPage();
    ccmMenuMigration()->up();
    $admin = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;
    $other = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    ccmGrantMigration()->up();
    ccmGrantMigration()->up();

    $granted = DB::table('permissions')->join('menus', 'menus.id', '=', 'permissions.menu_id')->where('permissions.role_id', $admin)->pluck('menus.route_path')->sort()->values()->all();

    expect($granted)->toBe(['/cashcollection/advance', '/cashcollection/reverse'])
        ->and(DB::table('permissions')->where('role_id', $other)->count())->toBe(0);

    ccmGrantMigration()->down();
    expect(DB::table('permissions')->where('role_id', $admin)->count())->toBe(0);
});

test('rolling the menu migration back removes the rows and their permissions and clears the caches', function () {
    ccmSeedCashCollectionPage();
    ccmMenuMigration()->up();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;
    $reverseId = (int) DB::table('menus')->where('route_path', '/cashcollection/reverse')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $reverseId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Cache::put("user_permission_paths:{$roleId}", ['stale'], 600);

    ccmMenuMigration()->down();

    expect(DB::table('menus')->whereIn('route_path', ['/cashcollection/reverse', '/cashcollection/advance'])->count())->toBe(0)
        ->and(DB::table('permissions')->where('menu_id', $reverseId)->count())->toBe(0)
        ->and(Cache::has("user_permission_paths:{$roleId}"))->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/cashcollection')->exists())->toBeTrue();
});
