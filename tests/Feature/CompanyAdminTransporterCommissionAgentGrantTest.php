<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The migration that gives the global companyadmin role the Transporter and Commission Agent pages and
 * their hidden rows. The menu rows come from 2026_09_21_180300 / 180400, which find their group through
 * a legacy page row that exists only in live data, so the tests seed those anchors and run both.
 */
function tcgGrant(): object
{
    return require database_path('migrations/2026_09_21_190000_grant_companyadmin_transporter_commission_agent_menus.php');
}

function tcgSeedMenus(): void
{
    foreach ([['Purchase', '/purchase', '2026_09_21_180300_add_transporter_menu.php'], ['User Management', '/user', '2026_09_21_180400_add_commission_agent_menu.php']] as [$group, $anchor, $file]) {
        DB::table('menus')->where('route_path', $anchor)->delete();

        $row = fn (array $attributes): array => $attributes + [
            'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
            'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
        ];

        $groupId = DB::table('menus')->insertGetId($row([
            'parent_id' => null, 'name' => $group, 'route_name' => 'tcg-'.strtolower($group), 'route_path' => '#tcg-'.strtolower($group),
        ]));
        DB::table('menus')->insert($row(['parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => ltrim($anchor, '/'), 'route_path' => $anchor]));

        (require database_path('migrations/'.$file))->up();
    }
}

function tcgRole(string $name = 'companyadmin', ?int $companyId = null): int
{
    return DB::table('roles')->insertGetId([
        'name' => $name, 'company_id' => $companyId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @return list<int>
 */
function tcgMenuIds(): array
{
    return DB::table('menus')
        ->where(fn ($query) => $query->where('route_path', 'like', '/transporter%')->orWhere('route_path', 'like', '/commissionagent%'))
        ->orderBy('id')
        ->pluck('id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();
}

test('the menu rows this grants exist: 2 pages and 12 hidden rows', function () {
    tcgSeedMenus();

    expect(tcgMenuIds())->toHaveCount(14)
        ->and(DB::table('menus')->whereIn('id', tcgMenuIds())->where('is_hidden', 1)->count())->toBe(12);
});

test('the global companyadmin role gets exactly the 14 rows', function () {
    tcgSeedMenus();
    $roleId = tcgRole();

    tcgGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->orderBy('menu_id')->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe(tcgMenuIds())
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->count())->toBe(14);

    $row = DB::table('permissions')->where('role_id', $roleId)->first();

    expect($row->company_id)->toBeNull()
        ->and($row->branch_id)->toBeNull()
        ->and($row->department_id)->toBeNull();
});

test('other roles and company specific admin roles are left alone', function () {
    tcgSeedMenus();
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'TCG001', 'name' => 'Grant Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    tcgRole('test role', $companyId);
    tcgRole('companyadmin', $companyId);
    tcgRole('superadmin');
    tcgRole('accountant');

    tcgGrant()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('running it twice keeps one enabled row per menu and re-enables a denied one', function () {
    tcgSeedMenus();
    $roleId = tcgRole();
    [$denied, $kept] = tcgMenuIds();

    DB::table('permissions')->insert([
        ['role_id' => $roleId, 'menu_id' => $denied, 'status' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $roleId, 'menu_id' => $kept, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    tcgGrant()->up();
    tcgGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(14)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $denied)->value('status'))->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->count())->toBe(14);
});

test('an existing permission of the role on another menu is not touched', function () {
    tcgSeedMenus();
    $roleId = tcgRole();
    $anchorId = (int) DB::table('menus')->where('route_path', '/purchase')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $anchorId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    tcgGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(15)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $anchorId)->where('status', 1)->count())->toBe(1);
});

test('without the role or without the menu rows the migration does nothing, and a missing row is skipped', function () {
    tcgGrant()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    tcgSeedMenus();
    tcgGrant()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    $roleId = tcgRole();
    DB::table('menus')->where('route_path', '/transporter/export')->delete();

    tcgGrant()->up();
    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(13);

    DB::table('permissions')->delete();
    DB::table('menus')->whereIn('id', tcgMenuIds())->delete();
    tcgGrant()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('the granted paths show up for the role and the menu caches are flushed', function () {
    tcgSeedMenus();
    $roleId = tcgRole();
    $keys = ["user_permission_paths:{$roleId}", "user_menu_permissions_tree:{$roleId}", "user_menu_permissions:{$roleId}"];

    foreach ($keys as $key) {
        Cache::put($key, ['stale'], 600);
    }

    tcgGrant()->up();

    foreach ($keys as $key) {
        expect(Cache::has($key))->toBeFalse();
    }

    expect(Menu::permittedRoutePathsForRole($roleId))->toContain('/transporter', '/transporter/export', '/commissionagent', '/commissionagent/:id/edit');
});

test('rolling back removes only those rows', function () {
    tcgSeedMenus();
    $roleId = tcgRole();
    $otherRole = tcgRole('accountant');
    $anchorId = (int) DB::table('menus')->where('route_path', '/purchase')->value('id');
    $transporterId = (int) DB::table('menus')->where('route_path', '/transporter')->value('id');
    DB::table('permissions')->insert([
        ['role_id' => $roleId, 'menu_id' => $anchorId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $otherRole, 'menu_id' => $transporterId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    tcgGrant()->up();
    tcgGrant()->down();

    expect(DB::table('permissions')->where('role_id', $roleId)->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$anchorId])
        ->and(DB::table('permissions')->where('role_id', $otherRole)->where('menu_id', $transporterId)->where('status', 1)->exists())->toBeTrue();
});
