<?php

use App\Models\Role;
use App\Models\Transaction;
use App\Services\StockMovements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $query
 */
function whRows(array $query = []): Collection
{
    return prpRows(prpGet('warehouse-stock', array_merge(['show_record' => 200], $query)));
}

/**
 * The warehouse rows of each branch must add up to that branch's stock, whatever is tagged.
 *
 * @param  array{scope: array<string, int>, other_branch: int, product: array<string, int>}  $life
 */
function whAssertAddsUp(array $life, Collection $rows): void
{
    foreach ([$life['scope']['branch_id'], $life['other_branch']] as $branchId) {
        $sum = round((float) $rows->where('branch_id', $branchId)->sum('on_hand'), 6);

        expect($sum)->toEqual(StockMovements::baseStock($life['product']['product_id'], $life['product']['variation_id'], $branchId));
    }
}

/**
 * Purchases into A Main, sales and adjustments out of A Overflow, the transfer from A Main to B Main.
 *
 * @return array{a1: int, a2: int, b1: int}
 */
function whTagAll(array $life): array
{
    $company = $life['scope']['company_id'];
    $a1 = wgWarehouse($company, $life['scope']['branch_id'], 'A Main');
    $a2 = wgWarehouse($company, $life['scope']['branch_id'], 'A Overflow');
    $b1 = wgWarehouse($company, $life['other_branch'], 'B Main');

    DB::table('transactions')->where('type', Transaction::TYPE_PURCHASE)->update(['warehouse_id' => $a1]);
    DB::table('transactions')->whereIn('type', ['sell', 'adjustment'])->update(['warehouse_id' => $a2]);
    DB::table('transactions')->where('type', 'transfer')->update(['warehouse_id' => $a1, 'towarehouse_id' => $b1]);

    return compact('a1', 'a2', 'b1');
}

test('with nothing tagged all stock is Unassigned and equals each branch\'s stock', function () {
    $life = stkLife();
    trpActAsSuperadmin();

    $rows = whRows();

    expect($rows->pluck('warehouse_name')->unique()->all())->toBe(['Unassigned'])
        ->and($rows->firstWhere('branch_id', $life['scope']['branch_id'])['on_hand'])->toEqual(41)
        ->and($rows->firstWhere('branch_id', $life['other_branch'])['on_hand'])->toEqual(20)
        ->and($rows->firstWhere('branch_id', $life['scope']['branch_id'])['branch_total'])->toEqual(41);

    whAssertAddsUp($life, $rows);
});

test('a fully tagged book splits each branch\'s stock by warehouse and the parts still add up to the branch', function () {
    $life = stkLife();
    trpActAsSuperadmin();
    whTagAll($life);

    $rows = whRows();
    $byName = $rows->keyBy('warehouse_name');

    // A Main: 100 bought - 10 returned - 20 sent to B; A Overflow: -30 sold + 5 returned - 4 written off (warehouses can go below
    // zero, because guards still look at the branch); B Main: the 20 that arrived.
    expect($byName['A Main']['on_hand'])->toEqual(70)
        ->and($byName['A Overflow']['on_hand'])->toEqual(-29)
        ->and($byName['B Main']['on_hand'])->toEqual(20)
        ->and($rows->where('warehouse_name', 'Unassigned'))->toHaveCount(0)
        ->and($byName['A Main']['branch_total'])->toEqual(41)
        ->and($byName['B Main']['branch_total'])->toEqual(20);

    whAssertAddsUp($life, $rows);
});

test('a half-tagged book shows the tagged part by warehouse and the rest as Unassigned', function () {
    $life = stkLife();
    trpActAsSuperadmin();
    $a1 = wgWarehouse($life['scope']['company_id'], $life['scope']['branch_id'], 'A Main');
    DB::table('transactions')->where('type', Transaction::TYPE_PURCHASE)->update(['warehouse_id' => $a1]);

    $rows = whRows();

    // A Main 90 (100 - 10 returned); everything else in A, -20 -30 +5 -4, is unassigned; B's arrival is unassigned too.
    expect($rows->where('branch_id', $life['scope']['branch_id'])->firstWhere('warehouse_name', 'A Main')['on_hand'])->toEqual(90)
        ->and($rows->where('branch_id', $life['scope']['branch_id'])->firstWhere('warehouse_name', 'Unassigned')['on_hand'])->toEqual(-49)
        ->and($rows->where('branch_id', $life['other_branch'])->firstWhere('warehouse_name', 'Unassigned')['on_hand'])->toEqual(20);

    whAssertAddsUp($life, $rows);
});

test('the view can show only assigned or only unassigned stock, on a day, and filter by product', function () {
    $life = stkLife();
    trpActAsSuperadmin();
    $a1 = wgWarehouse($life['scope']['company_id'], $life['scope']['branch_id'], 'A Main');
    DB::table('transactions')->where('type', Transaction::TYPE_PURCHASE)->update(['warehouse_id' => $a1]);

    expect(whRows(['status' => 'assigned'])->pluck('warehouse_name')->unique()->all())->toBe(['A Main'])
        ->and(whRows(['status' => 'unassigned'])->pluck('warehouse_name')->unique()->all())->toBe(['Unassigned']);

    // On 31 August only the purchase and its return exist.
    $early = whRows(['end_date' => '2026-08-31']);
    expect($early)->toHaveCount(1)->and($early[0]['warehouse_name'])->toBe('A Main')->and($early[0]['on_hand'])->toEqual(90);

    expect(whRows(['search' => 'Lifecycle']))->not->toBeEmpty()->and(whRows(['search' => 'nothing like this']))->toBeEmpty();
    expect(prpGet('warehouse-stock', ['show_record' => 100, 'status' => 'all'])->json('summary.total_qty'))->toEqual(61);
});

test('one branch alone, and a branch user sees only their branch', function () {
    $life = stkLife();
    whTagAll($life);

    $role = Role::query()->create(['name' => 'storekeeper', 'company_id' => $life['scope']['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $life['scope']['company_id'], 'branch_id' => $life['other_branch']]));

    prpGet('warehouse-stock', ['company_id' => $life['scope']['company_id']])->assertForbidden();

    grantMenuPermission($role->id, '/report/warehouse-stock');
    $rows = whRows();

    expect($rows->pluck('branch_id')->unique()->all())->toBe([$life['other_branch']])
        ->and($rows[0]['warehouse_name'])->toBe('B Main');
});

test('the movements can be filtered by warehouse, 0 meaning unassigned, and the parts add up to the whole', function () {
    $life = stkLife();
    ['a1' => $a1, 'a2' => $a2, 'b1' => $b1] = whTagAll($life);
    $product = $life['product'];
    $sum = fn (array $filters): float => round((float) DB::query()->fromSub(StockMovements::query($filters + ['product_id' => $product['product_id'], 'variation_id' => $product['variation_id']]), 'm')->sum('m.qty'), 6);

    expect($sum(['warehouse_id' => $a1]))->toEqual(70)
        ->and($sum(['warehouse_id' => $a2]))->toEqual(-29)
        ->and($sum(['warehouse_id' => $b1]))->toEqual(20)
        ->and($sum(['warehouse_id' => 0]))->toEqual(0)
        ->and($sum(['warehouse_id' => $a1]) + $sum(['warehouse_id' => $a2]) + $sum(['warehouse_id' => $b1]) + $sum(['warehouse_id' => 0]))->toEqual($sum([]))
        ->and($sum(['warehouse_id' => $a1, 'branch_id' => $life['other_branch']]))->toEqual(0);
});

test('the warehouse stock menu migration adds the report next to Stock and grants it to companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $reports = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Reports', 'icon' => '', 'route_name' => 'reportsgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('menus')->insert([
        'parent_id' => $reports, 'name' => 'Stock', 'icon' => '', 'route_name' => 'report.stock', 'route_path' => '/report/stock', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_18_100100_add_warehouse_stock_menu.php'))->up();

    expect((int) DB::table('menus')->where('route_path', '/report/warehouse-stock')->value('parent_id'))->toBe($reports)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(1);
});
