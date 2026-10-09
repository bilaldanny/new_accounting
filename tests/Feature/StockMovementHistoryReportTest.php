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
function smhRows(array $query = []): Collection
{
    return prpRows(prpGet('stock-movement-history', array_merge(['show_record' => 200, 'start_date' => '2026-08-01', 'end_date' => '2026-09-30'], $query)));
}

test('the history lists every movement of the life with its document, in and out, and nets to the stock', function () {
    $life = stkLife();
    trpActAsSuperadmin();

    $rows = smhRows();
    $byMovement = $rows->groupBy('movement');

    expect($byMovement->keys()->sort()->values()->all())->toBe(['Adjustment', 'Purchase received', 'Purchase return', 'Sale', 'Sale return', 'Transfer in', 'Transfer out'])
        ->and($byMovement['Purchase received'][0]['qty_in'])->toEqual(100)
        ->and($byMovement['Purchase return'][0]['qty_out'])->toEqual(10)
        ->and($byMovement['Sale'][0]['qty_out'])->toEqual(30)
        ->and($byMovement['Sale return'][0]['qty_in'])->toEqual(5)
        ->and($byMovement['Transfer out'][0]['qty_out'])->toEqual(20)
        ->and($byMovement['Transfer in'][0]['qty_in'])->toEqual(20)
        ->and($byMovement['Adjustment'][0]['qty_out'])->toEqual(4)
        ->and($rows->every(fn (array $row): bool => $row['document_no'] !== '' && $row['warehouse_name'] === 'Unassigned'))->toBeTrue();

    // In less out is the stock, per branch and across all of them.
    expect(round((float) $rows->sum('qty'), 6))->toEqual(61)
        ->and(round((float) $rows->where('branch_id', $life['scope']['branch_id'])->sum('qty'), 6))->toEqual(StockMovements::baseStock($life['product']['product_id'], $life['product']['variation_id'], $life['scope']['branch_id']))
        ->and(prpGet('stock-movement-history', ['start_date' => '2026-08-01', 'end_date' => '2026-09-30'])->json('summary'))->toMatchArray(['net' => 61.0, 'qty_in' => 125.0, 'qty_out' => 64.0]);
});

test('each movement carries the warehouse of its document, a transfer arriving in the other one', function () {
    $life = stkLife();
    trpActAsSuperadmin();
    ['a1' => $a1, 'a2' => $a2, 'b1' => $b1] = whTagAll($life);

    $rows = smhRows();
    $warehouse = fn (string $movement): string => $rows->firstWhere('movement', $movement)['warehouse_name'];

    expect($warehouse('Purchase received'))->toBe('A Main')
        ->and($warehouse('Sale'))->toBe('A Overflow')
        ->and($warehouse('Adjustment'))->toBe('A Overflow')
        ->and($warehouse('Transfer out'))->toBe('A Main')
        ->and($warehouse('Transfer in'))->toBe('B Main')
        ->and($rows->firstWhere('movement', 'Transfer in')['branch_id'])->toBe($life['other_branch']);

    // One warehouse, then the unassigned (none left).
    expect(smhRows(['warehouse_id' => $a1])->pluck('movement')->sort()->values()->all())->toBe(['Purchase received', 'Purchase return', 'Transfer out'])
        ->and(round((float) smhRows(['warehouse_id' => $a2])->sum('qty'), 6))->toEqual(-29)
        ->and(round((float) smhRows(['warehouse_id' => $b1])->sum('qty'), 6))->toEqual(20)
        ->and(smhRows(['warehouse_id' => 0]))->toBeEmpty();
});

test('the unassigned movements of a half-tagged book are found with warehouse 0', function () {
    $life = stkLife();
    trpActAsSuperadmin();
    $a1 = wgWarehouse($life['scope']['company_id'], $life['scope']['branch_id'], 'A Main');
    DB::table('transactions')->where('type', Transaction::TYPE_PURCHASE)->update(['warehouse_id' => $a1]);

    expect(round((float) smhRows(['warehouse_id' => 0])->sum('qty'), 6))->toEqual(-29 - 20 + 20)
        ->and(smhRows(['warehouse_id' => 0])->pluck('movement')->unique()->sort()->values()->all())->toBe(['Adjustment', 'Sale', 'Sale return', 'Transfer in', 'Transfer out'])
        ->and(round((float) smhRows(['warehouse_id' => $a1])->sum('qty'), 6) + round((float) smhRows(['warehouse_id' => 0])->sum('qty'), 6))->toEqual(61);
});

test('the range, the branch, the product and the search narrow the history', function () {
    $life = stkLife();
    trpActAsSuperadmin();

    expect(smhRows(['start_date' => '2026-09-01', 'end_date' => '2026-09-07'])->pluck('movement')->all())->toBe(['Sale', 'Sale return'])
        ->and(smhRows(['start_date' => '2026-08-01', 'end_date' => '2026-08-31'])->pluck('movement')->sort()->values()->all())->toBe(['Purchase received', 'Purchase return'])
        ->and(smhRows(['branch_id' => $life['other_branch']])->pluck('movement')->all())->toBe(['Transfer in'])
        ->and(smhRows(['product_id' => $life['product']['product_id'] + 999]))->toBeEmpty()
        ->and(smhRows(['search' => 'Lifecycle']))->not->toBeEmpty()
        ->and(smhRows(['search' => 'no such thing']))->toBeEmpty();
});

test('the default movement rows keep exactly their columns, and the document columns come only when asked', function () {
    stkLife();

    $plain = (array) StockMovements::query()->first();
    $withDocuments = (array) StockMovements::query(['with_documents' => true])->first();

    expect(array_keys($plain))->toBe(['product_id', 'variation_id', 'branch_id', 'warehouse_id', 'qty'])
        ->and(array_keys($withDocuments))->toBe(['product_id', 'variation_id', 'branch_id', 'warehouse_id', 'qty', 'transaction_id', 'document_no', 'document_date', 'document_type']);
});

test('the history needs its own permission and a branch user sees only their branch', function () {
    $life = stkLife();

    $role = Role::query()->create(['name' => 'storekeeper', 'company_id' => $life['scope']['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $life['scope']['company_id'], 'branch_id' => $life['other_branch']]));

    prpGet('stock-movement-history', ['company_id' => $life['scope']['company_id']])->assertForbidden();

    grantMenuPermission($role->id, '/report/stock-movement-history');
    expect(smhRows()->pluck('branch_id')->unique()->all())->toBe([$life['other_branch']]);
});

test('the stock view can also be narrowed to one warehouse or to the unassigned stock', function () {
    $life = stkLife();
    trpActAsSuperadmin();
    ['a1' => $a1] = whTagAll($life);

    expect(whRows(['warehouse_id' => $a1])->pluck('warehouse_name')->unique()->all())->toBe(['A Main'])
        ->and(whRows(['warehouse_id' => $a1])[0]['on_hand'])->toEqual(70)
        ->and(whRows(['warehouse_id' => 0]))->toBeEmpty();
});

test('the history menu migration adds the report next to Stock and grants it to companyadmin', function () {
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

    (require database_path('migrations/2026_10_19_100000_add_stock_movement_history_menu.php'))->up();

    expect((int) DB::table('menus')->where('route_path', '/report/stock-movement-history')->value('parent_id'))->toBe($reports)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(1);
});
