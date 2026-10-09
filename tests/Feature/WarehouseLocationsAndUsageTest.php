<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * @param  array<string, mixed>  $overrides
 */
function wlCreate(int $warehouseId, string $code, string $type, array $overrides = []): int
{
    return test()->postJson('/api/warehouse-locations', array_merge(['warehouse_id' => $warehouseId, 'code' => $code, 'name' => 'Place '.$code, 'type' => $type], $overrides))
        ->assertSuccessful()->json('data.id');
}

/**
 * @param  array<string, mixed>  $query
 */
function wuRows(array $query = []): Collection
{
    return prpRows(prpGet('warehouse-usage', array_merge(['show_record' => 100], $query)));
}

test('locations nest zone, rack, shelf, bin and show their path', function () {
    $scope = faScope();
    $warehouse = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Main');

    $zone = wlCreate($warehouse, 'Z1', 'zone', ['name' => 'Zone A']);
    $rack = wlCreate($warehouse, 'R1', 'rack', ['name' => 'Rack 3', 'parent_id' => $zone]);
    $shelf = wlCreate($warehouse, 'S1', 'shelf', ['name' => 'Shelf 2', 'parent_id' => $rack]);
    $bin = wlCreate($warehouse, 'B1', 'bin', ['name' => 'Bin 7', 'parent_id' => $shelf]);

    $location = $this->getJson("/api/warehouse-locations?warehouse_id={$warehouse}")->assertSuccessful()->json('data.data');
    $deepest = collect($location)->firstWhere('id', $bin);

    expect($location)->toHaveCount(4)
        ->and($deepest['path'])->toBe('Zone A / Rack 3 / Shelf 2 / Bin 7')
        ->and($deepest['parent_name'])->toBe('Shelf 2')
        ->and($deepest['warehouse_name'])->toBe('Main')
        ->and(collect($location)->firstWhere('id', $zone)['path'])->toBe('Zone A');

    // A bin straight under a zone skips levels, which is allowed (a finer kind); a coarser one under a finer is not.
    wlCreate($warehouse, 'B2', 'bin', ['parent_id' => $zone]);
    $this->postJson('/api/warehouse-locations', ['warehouse_id' => $warehouse, 'code' => 'Z2', 'name' => 'x', 'type' => 'zone', 'parent_id' => $rack])->assertUnprocessable()->assertJsonValidationErrors(['type']);
    $this->postJson('/api/warehouse-locations', ['warehouse_id' => $warehouse, 'code' => 'R9', 'name' => 'x', 'type' => 'rack', 'parent_id' => $rack])->assertUnprocessable()->assertJsonValidationErrors(['type']);
});

test('location input is validated: unique code per warehouse, own warehouse and parent, no loops', function () {
    $scope = faScope();
    $warehouse = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Main');
    $second = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Second');
    $zone = wlCreate($warehouse, 'Z1', 'zone');
    $rack = wlCreate($warehouse, 'R1', 'rack', ['parent_id' => $zone]);

    $this->postJson('/api/warehouse-locations', ['warehouse_id' => $warehouse, 'code' => 'Z1', 'name' => 'Again', 'type' => 'zone'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    wlCreate($second, 'Z1', 'zone');
    $this->postJson('/api/warehouse-locations', ['warehouse_id' => $warehouse, 'code' => 'Z5', 'name' => 'x', 'type' => 'zone', 'parent_id' => wlCreate($second, 'Z9', 'zone')])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
    $this->postJson('/api/warehouse-locations', ['warehouse_id' => $warehouse, 'code' => 'X', 'name' => 'x', 'type' => 'pallet'])->assertUnprocessable()->assertJsonValidationErrors(['type']);

    $this->putJson("/api/warehouse-locations/{$zone}", ['code' => 'Z1', 'name' => 'Zone', 'type' => 'zone', 'parent_id' => $rack])->assertUnprocessable();
    $this->putJson("/api/warehouse-locations/{$zone}", ['code' => 'Z1', 'name' => 'Zone', 'type' => 'rack'])->assertUnprocessable()->assertJsonValidationErrors(['type']);
});

test('a location can be renamed and deleted, but not while it has sub-locations', function () {
    $scope = faScope();
    $warehouse = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Main');
    $zone = wlCreate($warehouse, 'Z1', 'zone');
    $rack = wlCreate($warehouse, 'R1', 'rack', ['parent_id' => $zone]);

    $this->putJson("/api/warehouse-locations/{$rack}", ['code' => 'R1', 'name' => 'Renamed rack', 'type' => 'rack', 'parent_id' => $zone, 'active' => false])->assertSuccessful()
        ->assertJsonPath('data.name', 'Renamed rack')->assertJsonPath('data.active', false);

    $this->deleteJson("/api/warehouse-locations/{$zone}")->assertUnprocessable()->assertJsonValidationErrors(['location']);
    $this->deleteJson("/api/warehouse-locations/{$rack}")->assertSuccessful();
    $this->deleteJson("/api/warehouse-locations/{$zone}")->assertSuccessful();
    expect(WarehouseLocation::query()->count())->toBe(0);
});

test('another company\'s locations are invisible and each action needs its own permission', function () {
    $scope = faScope();
    $other = faScope('FB');
    $mine = wlCreate(wgWarehouse($scope['company_id'], $scope['branch_id'], 'Main'), 'Z1', 'zone');
    $foreign = wgWarehouse($other['company_id'], $other['branch_id'], 'Theirs');
    wlCreate($foreign, 'Z1', 'zone');

    $role = Role::query()->create(['name' => 'storekeeper', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));

    $this->getJson('/api/warehouse-locations')->assertForbidden();
    grantMenuPermission($role->id, '/warehouselocation');
    $this->getJson('/api/warehouse-locations')->assertSuccessful()->assertJsonCount(1, 'data.data');
    $this->putJson("/api/warehouse-locations/{$mine}", ['code' => 'Z1', 'name' => 'x', 'type' => 'zone'])->assertForbidden();
    $this->deleteJson("/api/warehouse-locations/{$mine}")->assertForbidden();
    $this->postJson('/api/warehouse-locations', ['warehouse_id' => $mine, 'code' => 'Z2', 'name' => 'x', 'type' => 'zone'])->assertForbidden();

    // With the add permission a user still cannot put a location in another company's warehouse.
    grantMenuPermission($role->id, '/warehouselocation/add');
    $this->postJson('/api/warehouse-locations', ['warehouse_id' => $foreign, 'code' => 'Z2', 'name' => 'x', 'type' => 'zone'])->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id']);
});

test('a warehouse takes a capacity through its form', function () {
    $scope = faScope();
    $warehouse = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Main');

    $this->putJson("/api/warehouses/{$warehouse}", ['branch_id' => $scope['branch_id'], 'name' => 'Main warehouse', 'capacity' => 250.5])->assertSuccessful();
    expect((float) Warehouse::query()->findOrFail($warehouse)->capacity)->toBe(250.5);

    $this->putJson("/api/warehouses/{$warehouse}", ['branch_id' => $scope['branch_id'], 'name' => 'Main warehouse', 'capacity' => -5])->assertUnprocessable()->assertJsonValidationErrors(['capacity']);
    $this->putJson("/api/warehouses/{$warehouse}", ['branch_id' => $scope['branch_id'], 'name' => 'Main warehouse', 'capacity' => null])->assertSuccessful();
    expect(Warehouse::query()->findOrFail($warehouse)->capacity)->toBeNull();
});

test('usage is the stock in base units each warehouse holds against its capacity, and a variation below zero frees nothing', function () {
    $life = stkLife();
    trpActAsSuperadmin();
    ['a1' => $a1, 'a2' => $a2, 'b1' => $b1] = whTagAll($life);
    $none = wgWarehouse($life['scope']['company_id'], $life['scope']['branch_id'], 'No limit');
    $idle = wgWarehouse($life['scope']['company_id'], $life['scope']['branch_id'], 'Idle');
    DB::table('warehouses')->where('id', $a1)->update(['capacity' => 100]);
    DB::table('warehouses')->where('id', $a2)->update(['capacity' => 10]);
    DB::table('warehouses')->where('id', $b1)->update(['capacity' => 15]);
    DB::table('warehouses')->where('id', $idle)->update(['capacity' => 50, 'is_active' => 0]);

    $rows = wuRows()->keyBy('warehouse_name');

    expect($rows->keys()->sort()->values()->all())->toBe(['A Main', 'A Overflow', 'B Main', 'No limit'])
        ->and($rows['A Main']['used'])->toEqual(70)->and($rows['A Main']['usage_percent'])->toEqual(70)->and($rows['A Main']['free'])->toEqual(30)->and($rows['A Main']['status'])->toBe('Within capacity')
        ->and($rows['A Overflow']['used'])->toEqual(0)->and($rows['A Overflow']['usage_percent'])->toEqual(0)
        ->and($rows['B Main']['used'])->toEqual(20)->and($rows['B Main']['status'])->toBe('Over capacity')->and($rows['B Main']['free'])->toEqual(-5)
        ->and($rows['No limit']['capacity'])->toBeNull()->and($rows['No limit']['usage_percent'])->toBeNull()->and($rows['No limit']['status'])->toBe('No capacity set');

    $summary = prpGet('warehouse-usage', ['show_record' => 100])->json('summary');
    expect($summary['warehouses'])->toBe(4)->and($summary['capacity'])->toEqual(125)->and($summary['used'])->toEqual(90)->and($summary['over_capacity'])->toBe(1);

    // Nearly full at 90% or more, on an earlier day, per branch, and by name.
    DB::table('warehouses')->where('id', $a1)->update(['capacity' => 75]);
    expect(wuRows()->firstWhere('warehouse_name', 'A Main')['status'])->toBe('Nearly full')
        ->and(wuRows(['end_date' => '2026-08-31'])->firstWhere('warehouse_name', 'A Main')['used'])->toEqual(90)
        ->and(wuRows(['branch_id' => $life['other_branch']])->pluck('warehouse_name')->all())->toBe(['B Main'])
        ->and(wuRows(['search' => 'Overflow'])->pluck('warehouse_name')->all())->toBe(['A Overflow']);
});

test('the usage report needs its own permission and a branch user sees only their branch', function () {
    $life = stkLife();
    whTagAll($life);

    $role = Role::query()->create(['name' => 'storekeeper', 'company_id' => $life['scope']['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $life['scope']['company_id'], 'branch_id' => $life['other_branch']]));

    prpGet('warehouse-usage', ['company_id' => $life['scope']['company_id']])->assertForbidden();

    grantMenuPermission($role->id, '/report/warehouse-usage');
    expect(wuRows()->pluck('warehouse_name')->all())->toBe(['B Main']);
});

test('the location menu migration adds the page and the report with their rows and grants them to companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $inventory = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Inventory', 'icon' => '', 'route_name' => 'invgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $reports = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Reports', 'icon' => '', 'route_name' => 'reportsgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 2,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([[$inventory, 'Warehouses', '/warehouse'], [$reports, 'Stock', '/report/stock']] as [$parent, $name, $path]) {
        DB::table('menus')->insert([
            'parent_id' => $parent, 'name' => $name, 'icon' => '', 'route_name' => ltrim($path, '/'), 'route_path' => $path, 'menu_color' => '#000', 'sort_order' => 1,
            'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_20_100100_add_warehouse_location_menus.php'))->up();

    expect(DB::table('menus')->where('route_path', 'like', '/warehouselocation%')->orderBy('route_path')->pluck('route_path')->all())->toBe(['/warehouselocation', '/warehouselocation/:id/edit', '/warehouselocation/add', '/warehouselocation/delete'])
        ->and((int) DB::table('menus')->where('route_path', '/warehouselocation')->value('parent_id'))->toBe($inventory)
        ->and((int) DB::table('menus')->where('route_path', '/report/warehouse-usage')->value('parent_id'))->toBe($reports)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(5);
});
