<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The two menu migrations. Both find their group through a legacy page row (`/purchase`, `/user`) that
 * exists only in live data, so the tests seed those first.
 */
function tcMigration(string $module): object
{
    return require database_path('migrations/'.[
        'transporter' => '2026_09_21_180300_add_transporter_menu.php',
        'commissionagent' => '2026_09_21_180400_add_commission_agent_menu.php',
    ][$module]);
}

/**
 * A menu group with one legacy page in it, the way the live data has it. Returns the group's id.
 */
function tcSeedGroup(string $groupName, string $anchorPath): int
{
    DB::table('menus')->where('route_path', $anchorPath)->delete();

    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $groupId = DB::table('menus')->insertGetId($row([
        'parent_id' => null, 'name' => $groupName, 'route_name' => strtolower(str_replace(' ', '-', $groupName)).'-group', 'route_path' => '#'.strtolower($groupName),
    ]));

    DB::table('menus')->insert($row([
        'parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => ltrim($anchorPath, '/'), 'route_path' => $anchorPath, 'sort_order' => 9,
    ]));

    return $groupId;
}

dataset('master data menus', [
    'transporter' => [[
        'module' => 'transporter', 'path' => '/transporter', 'group' => 'Purchase', 'anchor' => '/purchase', 'icon' => 'Truck', 'routeName' => 'transporter',
    ]],
    'commission agent' => [[
        'module' => 'commissionagent', 'path' => '/commissionagent', 'group' => 'User Management', 'anchor' => '/user', 'icon' => 'Percentage', 'routeName' => 'commissionagent',
    ]],
]);

test('the menu migration adds the page in its group with the hidden permission rows', function (array $module) {
    $groupId = tcSeedGroup($module['group'], $module['anchor']);

    tcMigration($module['module'])->up();

    $page = DB::table('menus')->where('route_path', $module['path'])->first();

    expect($page)->not->toBeNull()
        ->and((int) $page->parent_id)->toBe($groupId)
        ->and($page->icon)->toBe($module['icon'])
        ->and($page->route_name)->toBe($module['routeName'])
        ->and((int) $page->is_hidden)->toBe(0)
        ->and((int) $page->is_active)->toBe(1)
        ->and((int) $page->sort_order)->toBe(10);

    $hidden = DB::table('menus')->where('parent_id', $page->id)->orderBy('sort_order')->get();

    expect($hidden->pluck('route_path')->all())->toBe([
        $module['path'].'/add',
        $module['path'].'/:id/edit',
        $module['path'].'/delete',
        $module['path'].'/:id/view',
        $module['path'].'/restore',
        $module['path'].'/export',
    ])->and($hidden->every(fn (object $row): bool => (int) $row->is_hidden === 1 && (int) $row->is_active === 1))->toBeTrue();
})->with('master data menus');

test('the menu migration is idempotent and grants nothing to any role', function (array $module) {
    tcSeedGroup($module['group'], $module['anchor']);
    $roleId = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    tcMigration($module['module'])->up();
    $menuRows = DB::table('menus')->where('route_path', 'like', $module['path'].'%')->count();
    tcMigration($module['module'])->up();

    expect($menuRows)->toBe(7)
        ->and(DB::table('menus')->where('route_path', 'like', $module['path'].'%')->count())->toBe(7)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
})->with('master data menus');

test('the menu migration does nothing when the group cannot be found', function (array $module) {
    DB::table('menus')->where('route_path', $module['anchor'])->delete();

    tcMigration($module['module'])->up();

    expect(DB::table('menus')->where('route_path', 'like', $module['path'].'%')->count())->toBe(0);
})->with('master data menus');

test('rolling the menu migration back removes the rows and the permissions on them', function (array $module) {
    tcSeedGroup($module['group'], $module['anchor']);
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    tcMigration($module['module'])->up();
    $addId = (int) DB::table('menus')->where('route_path', $module['path'].'/add')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $addId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    tcMigration($module['module'])->down();

    expect(DB::table('menus')->where('route_path', 'like', $module['path'].'%')->count())->toBe(0)
        ->and(DB::table('permissions')->where('menu_id', $addId)->count())->toBe(0)
        ->and(DB::table('menus')->where('route_path', $module['anchor'])->exists())->toBeTrue();
})->with('master data menus');

test('the menu migration clears the cached menu permissions of the roles', function (array $module) {
    tcSeedGroup($module['group'], $module['anchor']);
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    tcMigration($module['module'])->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
    }
})->with('master data menus');

test('the transporter migration leaves the commission agent menu alone and the other way round', function () {
    tcSeedGroup('Purchase', '/purchase');
    tcSeedGroup('User Management', '/user');

    tcMigration('transporter')->up();

    expect(DB::table('menus')->where('route_path', 'like', '/commissionagent%')->count())->toBe(0);

    tcMigration('commissionagent')->up();
    tcMigration('transporter')->down();

    expect(DB::table('menus')->where('route_path', 'like', '/commissionagent%')->count())->toBe(7)
        ->and(DB::table('menus')->where('route_path', 'like', '/transporter%')->count())->toBe(0);
});

test('guests are sent away from the transporter and commission agent pages', function (string $routeName, array $parameters) {
    $this->get(route($routeName, $parameters))->assertRedirect();
})->with([
    'transporter list' => ['transporter', []],
    'transporter add' => ['transporter.add', []],
    'transporter edit' => ['transporter.edit', [3]],
    'transporter view' => ['transporter.view', [3]],
    'transporter trash' => ['transporter.trash', []],
    'commission agent list' => ['commissionagent', []],
    'commission agent add' => ['commissionagent.add', []],
    'commission agent edit' => ['commissionagent.edit', [3]],
    'commission agent view' => ['commissionagent.view', [3]],
    'commission agent trash' => ['commissionagent.trash', []],
]);

test('the superadmin can open every transporter and commission agent page', function (string $routeName, array $parameters, string $component) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($routeName, $parameters))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'transporter list' => ['transporter', [], 'transporter/index'],
    'transporter add' => ['transporter.add', [], 'transporter/add'],
    'transporter edit' => ['transporter.edit', [3], 'transporter/edit'],
    'transporter view' => ['transporter.view', [3], 'transporter/view'],
    'transporter trash' => ['transporter.trash', [], 'transporter/trash'],
    'commission agent list' => ['commissionagent', [], 'commissionagent/index'],
    'commission agent add' => ['commissionagent.add', [], 'commissionagent/add'],
    'commission agent edit' => ['commissionagent.edit', [3], 'commissionagent/edit'],
    'commission agent view' => ['commissionagent.view', [3], 'commissionagent/view'],
    'commission agent trash' => ['commissionagent.trash', [], 'commissionagent/trash'],
]);

test('the edit and view pages pass the record id', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)->get(route('transporter.view', 12))
        ->assertInertia(fn ($page) => $page->component('transporter/view')->where('id', '12'));
    $this->actingAs($superadmin)->get(route('commissionagent.edit', 12))
        ->assertInertia(fn ($page) => $page->component('commissionagent/edit')->where('id', '12'));
});

test('a user without the menu permission gets a 403 on each page', function (string $routeName, array $parameters) {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);

    $this->actingAs(createStaffUserForRole($role))
        ->get(route($routeName, $parameters))
        ->assertForbidden();
})->with([
    'transporter list' => ['transporter', []],
    'transporter add' => ['transporter.add', []],
    'transporter edit' => ['transporter.edit', [3]],
    'transporter view' => ['transporter.view', [3]],
    'transporter trash' => ['transporter.trash', []],
    'commission agent list' => ['commissionagent', []],
    'commission agent add' => ['commissionagent.add', []],
    'commission agent edit' => ['commissionagent.edit', [3]],
    'commission agent view' => ['commissionagent.view', [3]],
    'commission agent trash' => ['commissionagent.trash', []],
]);

test('a user is let into exactly the pages their menu permissions name', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);
    grantMenuPermission($role->id, '/transporter');
    grantMenuPermission($role->id, '/transporter/:id/view');
    grantMenuPermission($role->id, '/commissionagent/add');
    $user = createStaffUserForRole($role);

    $this->actingAs($user)->get(route('transporter'))->assertSuccessful();
    $this->actingAs($user)->get(route('transporter.view', 3))->assertSuccessful();
    $this->actingAs($user)->get(route('transporter.add'))->assertForbidden();
    $this->actingAs($user)->get(route('transporter.edit', 3))->assertForbidden();
    $this->actingAs($user)->get(route('commissionagent.add'))->assertSuccessful();
    $this->actingAs($user)->get(route('commissionagent'))->assertForbidden();
});
