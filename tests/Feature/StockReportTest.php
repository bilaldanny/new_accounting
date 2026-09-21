<?php

use App\Http\Controllers\Reports\StockReportController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\StockMovements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The stock report (stock per variation on a day, broken down by movement, with value) and the stock
 * transfer report.
 */

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function stkQuery(array $extra = []): array
{
    return array_merge(['show_record' => 100], $extra);
}

/**
 * Two branches, one product with a full life: 100 purchased at 5 in branch A, 10 sent back to the
 * supplier, 30 sold (5 of them returned), 20 moved from A to B and 4 written off in A.
 *
 * @return array{scope: array<string, int>, other_branch: int, product: array{product_id: int, variation_id: int, unit_id: int}}
 */
function stkLife(): array
{
    $scope = trpScope();
    $otherBranch = trpBranch($scope['company_id'], 'Branch B');
    $product = prdProduct($scope, 'Lifecycle', [], ['default_sell_price' => 8]);

    prdPurchase($scope, $product, 100, 5, '2026-08-01', [], ['quantity_returned' => 10]);
    prdSale($scope, $product, 30, 8, '2026-09-05', [], ['quantity_returned' => 5]);

    $transfer = trpDoc($scope, 'transfer', ['status' => 'completed', 'tobranch_id' => $otherBranch, 'transaction_date' => '2026-09-10', 'contact_id' => null]);
    prdPurchaseLine($transfer, $product, 20, 5, ['quantity_received' => 0]);

    $adjustment = trpDoc($scope, 'adjustment', ['status' => 'completed', 'transaction_date' => '2026-09-15', 'contact_id' => null]);
    prdPurchaseLine($adjustment, $product, 0, 0, ['quantity_received' => 0, 'quantity_adjustment' => -4]);

    return ['scope' => $scope, 'other_branch' => $otherBranch, 'product' => $product];
}

test('the stock breaks down by movement and adds up to the one definition of stock', function () {
    $life = stkLife();
    trpActAsSuperadmin();

    $row = prpRow(prpGet('stock', stkQuery()), 1);

    // 100 - 10 - 30 + 5 + 20 - 20 - 4 across both branches.
    expect($row['product_name'])->toBe('Lifecycle')
        ->and($row['purchased'])->toEqual(100)
        ->and($row['purchase_returned'])->toEqual(10)
        ->and($row['sold'])->toEqual(30)
        ->and($row['sale_returned'])->toEqual(5)
        ->and($row['transferred_in'])->toEqual(20)
        ->and($row['transferred_out'])->toEqual(20)
        ->and($row['adjusted'])->toEqual(-4)
        ->and($row['current_stock'])->toEqual(61)
        ->and($row['current_stock'])->toEqual(StockMovements::baseStock($life['product']['product_id'], $life['product']['variation_id']))
        ->and($row['purchased'] - $row['purchase_returned'] - $row['sold'] + $row['sale_returned'] + $row['transferred_in'] - $row['transferred_out'] + $row['adjusted'])->toEqual($row['current_stock']);
});

test('the stock is shown for one branch or per branch and matches the stock of each', function () {
    $life = stkLife();
    trpActAsSuperadmin();

    $product = $life['product'];
    $branchA = $life['scope']['branch_id'];
    $branchB = $life['other_branch'];

    $perBranch = prpRows(prpGet('stock', stkQuery(['by_branch' => 'true'])));
    $a = $perBranch->firstWhere('branch_id', $branchA);
    $b = $perBranch->firstWhere('branch_id', $branchB);
    $onlyB = prpRows(prpGet('stock', stkQuery(['branch_id' => $branchB])));

    expect($perBranch)->toHaveCount(2)
        ->and($a['current_stock'])->toEqual(41)
        ->and($a['current_stock'])->toEqual(StockMovements::baseStock($product['product_id'], $product['variation_id'], $branchA))
        ->and($a['transferred_out'])->toEqual(20)
        ->and($a['branch_name'])->toBe('Report Branch 1')
        ->and($b['current_stock'])->toEqual(20)
        ->and($b['current_stock'])->toEqual(StockMovements::baseStock($product['product_id'], $product['variation_id'], $branchB))
        ->and($b['transferred_in'])->toEqual(20)
        ->and($onlyB)->toHaveCount(1)
        ->and($onlyB->first()['current_stock'])->toEqual(20);
});

test('the stock is as of the day asked for, inclusive', function () {
    stkLife();
    trpActAsSuperadmin();

    $stock = fn (string $day): float => (float) prpRows(prpGet('stock', stkQuery(['end_date' => $day])))->first()['current_stock'];

    // Nothing had happened yet on 31 July.
    expect(prpRows(prpGet('stock', stkQuery(['end_date' => '2026-07-31'])))->isEmpty())->toBeTrue()
        ->and($stock('2026-08-01'))->toBe(90.0)
        ->and($stock('2026-09-05'))->toBe(65.0)
        ->and($stock('2026-09-09'))->toBe(65.0)
        ->and($stock('2026-09-10'))->toBe(65.0)
        ->and($stock('2026-09-14'))->toBe(65.0)
        ->and($stock('2026-09-15'))->toBe(61.0);
});

test('a line is counted in base units and drafts, quotations and deleted documents are not stock', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Boxed');
    prdPurchase($scope, $product, 2, 1, '2026-08-01', [], ['packing_qty' => 12]);
    prdSale($scope, $product, 1, 10, '2026-09-01', ['status' => 'final'], ['packing_qty' => 6]);
    prdSale($scope, $product, 5, 10, '2026-09-01', ['status' => 'draft']);
    prdSale($scope, $product, 5, 10, '2026-09-01', ['status' => 'quotation']);
    prdSale($scope, $product, 5, 10, '2026-09-01', ['deleted_at' => now()]);

    $row = prpRows(prpGet('stock', stkQuery()))->firstWhere('product_id', $product['product_id']);

    // 2 x 12 purchased, 1 x 6 sold.
    expect($row['purchased'])->toEqual(24)
        ->and($row['sold'])->toEqual(6)
        ->and($row['current_stock'])->toEqual(18);
});

test('stock value uses the average cost and the sell price, and an uncosted variation shows no value', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $costed = prdProduct($scope, 'Costed', [], ['default_sell_price' => 12]);
    $uncosted = prdProduct($scope, 'Uncosted', [], ['default_sell_price' => 12]);
    prdPurchase($scope, $costed, 10, 4, '2026-08-01');
    prdPurchase($scope, $costed, 10, 8, '2026-09-01');
    prdSale($scope, $costed, 5, 12, '2026-09-10');
    $opening = trpDoc($scope, 'adjustment', ['status' => 'completed', 'transaction_date' => '2026-09-01', 'contact_id' => null]);
    prdPurchaseLine($opening, $uncosted, 0, 0, ['quantity_received' => 0, 'quantity_adjustment' => 7]);

    $response = prpGet('stock', stkQuery());
    $row = prpRows($response)->firstWhere('product_id', $costed['product_id']);
    $none = prpRows($response)->firstWhere('product_id', $uncosted['product_id']);
    $early = prpRows(prpGet('stock', stkQuery(['end_date' => '2026-08-15'])))->firstWhere('product_id', $costed['product_id']);

    // Average cost (10 x 4 + 10 x 8) / 20 = 6, stock 15: value 90, sell value 180, potential profit 90.
    expect($row['average_cost'])->toEqual(6)
        ->and($row['stock_value'])->toEqual(90)
        ->and($row['sell_value'])->toEqual(180)
        ->and($row['potential_profit'])->toEqual(90)
        // On 15 August only the first purchase was known.
        ->and($early['average_cost'])->toEqual(4)
        ->and($early['stock_value'])->toEqual(40)
        ->and($none['current_stock'])->toEqual(7)
        ->and($none['average_cost'])->toBeNull()
        ->and($none['stock_value'])->toBeNull()
        ->and($none['potential_profit'])->toBeNull()
        ->and($response->json('summary'))->toMatchArray(['count' => 2, 'without_cost' => 1])
        ->and($response->json('summary.stock_value'))->toEqual(90)
        ->and($response->json('summary.potential_profit'))->toEqual(90);
});

test('the stock report filters by product, brand, category, unit and search', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $brandId = DB::table('brands')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'Acme Brand', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $categoryId = DB::table('categories')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'Stationery', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $pen = prdProduct($scope, 'Pen', ['brand_id' => $brandId, 'category_id' => $categoryId], ['sku' => 'PEN-1']);
    $ink = prdProduct($scope, 'Ink');
    prdPurchase($scope, $pen, 5, 1, '2026-08-01');
    prdPurchase($scope, $ink, 5, 1, '2026-08-01');

    $ids = fn (array $extra): array => prpRows(prpGet('stock', stkQuery($extra)))->pluck('product_id')->sort()->values()->all();

    expect($ids(['product_id' => $ink['product_id']]))->toBe([$ink['product_id']])
        ->and($ids(['brand_id' => $brandId]))->toBe([$pen['product_id']])
        ->and($ids(['category_id' => $categoryId]))->toBe([$pen['product_id']])
        ->and($ids(['unit_id' => $ink['unit_id']]))->toBe([$ink['product_id']])
        ->and($ids(['search' => 'pen-1']))->toBe([$pen['product_id']])
        ->and($ids(['search' => 'ink']))->toBe([$ink['product_id']])
        ->and(prpRows(prpGet('stock', stkQuery()))->firstWhere('product_id', $pen['product_id'])['brand_name'])->toBe('Acme Brand');
});

test('a deleted product is not in the stock report and rows sort by a known column', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $small = prdProduct($scope, 'Aaa Small');
    $large = prdProduct($scope, 'Zzz Large');
    $gone = prdProduct($scope, 'Removed');
    prdPurchase($scope, $small, 5, 1, '2026-08-01');
    prdPurchase($scope, $large, 50, 1, '2026-08-01');
    prdPurchase($scope, $gone, 500, 1, '2026-08-01');
    DB::table('products')->where('id', $gone['product_id'])->update(['deleted_at' => now()]);

    $order = fn (array $extra): array => prpRows(prpGet('stock', stkQuery($extra)))->pluck('product_name')->all();

    expect($order([]))->toBe(['Aaa Small', 'Zzz Large'])
        ->and($order(['sort_by' => 'current_stock', 'sort_type' => 'desc']))->toBe(['Zzz Large', 'Aaa Small'])
        ->and($order(['sort_by' => 'x; drop table users']))->toBe(['Aaa Small', 'Zzz Large']);
});

test('a company user sees only their own stock and a branch user only their branch', function () {
    $mine = trpScope('1');
    $theirs = trpScope('2');
    $otherBranch = trpBranch($mine['company_id'], 'Second Branch');

    $product = prdProduct($mine, 'Mine');
    $foreign = prdProduct($theirs, 'Theirs');
    prdPurchase($mine, $product, 10, 1, '2026-08-01');
    prdPurchase(array_merge($mine, ['branch_id' => $otherBranch]), $product, 4, 1, '2026-08-01');
    prdPurchase($theirs, $foreign, 99, 1, '2026-08-01');

    Sanctum::actingAs(jeaUserWith($mine, ['/report/stock']));

    $own = prpGet('stock', stkQuery(['company_id' => $theirs['company_id']]));

    expect(prpRows($own)->pluck('product_name')->all())->toBe(['Mine'])
        ->and(prpRows($own)->first()['current_stock'])->toEqual(10);

    prpGet('stock', stkQuery(['search' => "' OR 1=1 --"]))->assertSuccessful()->assertJsonPath('summary.count', 0);

    trpActAsSuperadmin();

    expect(prpRows(prpGet('stock', stkQuery(['company_id' => $mine['company_id']])))->first()['current_stock'])->toEqual(14);
});

// ------------------------------------------------------------------ stock transfers

test('the transfer report lists every status by default and narrows by status', function () {
    $scope = trpScope();
    $branchB = trpBranch($scope['company_id'], 'Branch B');
    trpActAsSuperadmin();

    $done = trpDoc($scope, 'transfer', ['status' => 'completed', 'tobranch_id' => $branchB, 'final_amount' => 100, 'total_item' => 3, 'contact_id' => null]);
    $waiting = trpDoc($scope, 'transfer', ['status' => 'pending', 'tobranch_id' => $branchB, 'final_amount' => 40, 'contact_id' => null]);
    $other = trpDoc($scope, 'transfer', ['status' => 'cancelled', 'tobranch_id' => $branchB, 'final_amount' => 5, 'contact_id' => null]);
    trpDoc($scope, 'adjustment', ['status' => 'completed', 'contact_id' => null]);
    trpDoc($scope, 'transfer', ['status' => 'completed', 'tobranch_id' => $branchB, 'final_amount' => 999, 'deleted_at' => now(), 'contact_id' => null]);

    $ids = fn (string $query): array => prpRows(prpGet('stock-transfer', stkQuery($query === '' ? [] : ['status' => $query])))->pluck('id')->sort()->values()->all();
    $everything = collect([$done, $waiting, $other])->sort()->values()->all();

    expect($ids(''))->toBe($everything)
        ->and($ids('all'))->toBe($everything)
        ->and($ids('completed'))->toBe([$done])
        ->and($ids('pending'))->toBe([$waiting]);

    $response = prpGet('stock-transfer', stkQuery());

    expect($response->json('summary'))->toMatchArray(['count' => 3])
        ->and($response->json('summary.total'))->toEqual(145)
        ->and($response->json('summary.completed'))->toEqual(100)
        ->and($response->json('summary.pending'))->toEqual(40)
        ->and(prpRow($response, $done))->toMatchArray(['from_branch' => 'Report Branch 1', 'to_branch' => 'Branch B', 'status' => 'completed', 'total_item' => 3]);
});

test('the transfer report filters by from and to branch, date and search', function () {
    $scope = trpScope();
    $branchB = trpBranch($scope['company_id'], 'Branch B');
    $branchC = trpBranch($scope['company_id'], 'Branch C');
    trpActAsSuperadmin();

    $ab = trpDoc($scope, 'transfer', ['tobranch_id' => $branchB, 'transaction_date' => '2026-09-04 23:59:59', 'invoice_no' => 'ST-AB', 'contact_id' => null]);
    $ac = trpDoc($scope, 'transfer', ['tobranch_id' => $branchC, 'transaction_date' => '2026-09-05 00:00:00', 'additional_note' => 'Fast moving', 'contact_id' => null]);
    $ba = trpDoc(array_merge($scope, ['branch_id' => $branchB]), 'transfer', ['tobranch_id' => $scope['branch_id'], 'transaction_date' => '2026-09-06', 'contact_id' => null]);

    $ids = fn (array $extra): array => prpRows(prpGet('stock-transfer', stkQuery($extra)))->pluck('id')->sort()->values()->all();
    $sorted = fn (array $values): array => collect($values)->sort()->values()->all();

    expect($ids(['from_branch_id' => $scope['branch_id']]))->toBe($sorted([$ab, $ac]))
        ->and($ids(['from_branch_id' => $branchB]))->toBe([$ba])
        ->and($ids(['to_branch_id' => $branchC]))->toBe([$ac])
        ->and($ids(['from_branch_id' => $scope['branch_id'], 'to_branch_id' => $branchB]))->toBe([$ab])
        ->and($ids(['start_date' => '2026-09-05', 'end_date' => '2026-09-05']))->toBe([$ac])
        ->and($ids(['end_date' => '2026-09-04']))->toBe([$ab])
        ->and($ids(['search' => 'ST-AB']))->toBe([$ab])
        ->and($ids(['search' => 'fast moving']))->toBe([$ac]);

    prpGet('stock-transfer', stkQuery(['start_date' => '2026-09-06', 'end_date' => '2026-09-05']))->assertUnprocessable();
});

test('a branch user sees the transfers they sent and the ones sent to them', function () {
    $scope = trpScope();
    $branchB = trpBranch($scope['company_id'], 'Branch B');
    $branchC = trpBranch($scope['company_id'], 'Branch C');

    $sent = trpDoc($scope, 'transfer', ['tobranch_id' => $branchB, 'contact_id' => null]);
    $received = trpDoc(array_merge($scope, ['branch_id' => $branchB]), 'transfer', ['tobranch_id' => $scope['branch_id'], 'contact_id' => null]);
    trpDoc(array_merge($scope, ['branch_id' => $branchB]), 'transfer', ['tobranch_id' => $branchC, 'contact_id' => null]);

    Sanctum::actingAs(jeaUserWith($scope, ['/report/stock-transfer']));

    expect(prpRows(prpGet('stock-transfer', stkQuery()))->pluck('id')->sort()->values()->all())->toBe(collect([$sent, $received])->sort()->values()->all());
});

test('a company user sees only their own transfers, the maker is named and hostile input stays data', function () {
    $mine = trpScope('1');
    $theirs = trpScope('2');
    $branchB = trpBranch($mine['company_id'], 'Branch B');
    $theirBranch = trpBranch($theirs['company_id'], 'Their B');

    $userId = DB::table('users')->insertGetId([
        'first_name' => 'Amina', 'last_name' => 'Rauf', 'username' => 'amina.rauf', 'email' => 'amina@example.com',
        'password' => 'x', 'pass' => '', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $own = trpDoc($mine, 'transfer', ['tobranch_id' => $branchB, 'created_by' => $userId, 'contact_id' => null]);
    trpDoc($theirs, 'transfer', ['tobranch_id' => $theirBranch, 'contact_id' => null]);

    Sanctum::actingAs(jeaUserWith($mine, ['/report/stock-transfer']));

    $response = prpGet('stock-transfer', stkQuery(['company_id' => $theirs['company_id']]));

    expect(prpRows($response)->pluck('id')->all())->toBe([$own])
        ->and(prpRow($response, $own)['created_by_name'])->toBe('Amina Rauf');

    prpGet('stock-transfer', stkQuery(['search' => "' OR 1=1 --", 'status' => "x' OR '1'='1"]))->assertSuccessful()->assertJsonPath('summary.count', 0);
});

// ------------------------------------------------------------------ access and menu rows

dataset('stock reports', [
    'stock' => ['stock', '/report/stock'],
    'stock transfer' => ['stock-transfer', '/report/stock-transfer'],
]);

test('the report answers with a page of rows and a summary, and needs its menu row', function (string $report, string $path) {
    $scope = trpScope();

    $this->getJson("/api/reports/{$report}")->assertUnauthorized();

    Sanctum::actingAs(jeaUserWith($scope, []));
    $this->getJson("/api/reports/{$report}")->assertForbidden();

    trpActAsSuperadmin();
    $this->getJson("/api/reports/{$report}")
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['data', 'current_page', 'last_page', 'per_page', 'total'], 'summary' => ['count'], 'trash_count']);

    Sanctum::actingAs(jeaUserWith($scope, [$path]));
    $this->getJson("/api/reports/{$report}")->assertSuccessful();
})->with('stock reports');

test('a page needs the same menu row and a signed in user', function (string $report, string $path) {
    $scope = trpScope();

    $this->get(route("report.{$report}"))->assertRedirect();
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route("report.{$report}"))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('report/index')->where('report', $report));
    $this->actingAs(jeaUserWith($scope, []))->get(route("report.{$report}"))->assertForbidden();
    $this->actingAs(jeaUserWith($scope, [$path]))->get(route("report.{$report}"))->assertSuccessful();

    expect(app('router')->getRoutes()->match(Request::create($path, 'GET'))->getName())->toBe("report.{$report}");
})->with('stock reports');

function stkMenuMigration(): object
{
    return require database_path('migrations/2026_09_21_140000_add_stock_report_menus.php');
}

test('the stock reports sit in the Reports group with a hidden export row and roll back cleanly', function () {
    expect(array_keys(StockReportController::PERMISSIONS))->toBe(['stock', 'stock-transfer']);

    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Reports')->where('type', 2)->value('id');
    $paths = array_values(StockReportController::PERMISSIONS);

    foreach ($paths as $path) {
        $page = DB::table('menus')->where('route_path', $path)->first();
        $export = DB::table('menus')->where('route_path', $path.'/export')->first();

        expect($page)->not->toBeNull()
            ->and((int) $page->parent_id)->toBe((int) $groupId)
            ->and($export)->not->toBeNull()
            ->and((int) $export->is_hidden)->toBe(1);
    }

    $before = DB::table('menus')->count();
    stkMenuMigration()->up();
    expect(DB::table('menus')->count())->toBe($before);

    $pageId = (int) DB::table('menus')->where('route_path', '/report/stock')->value('id');
    Permission::query()->create(['role_id' => Role::query()->create(['name' => 'keeper', 'is_active' => true])->id, 'menu_id' => $pageId, 'status' => 1]);

    stkMenuMigration()->down();

    expect(DB::table('menus')->whereIn('route_path', $paths)->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $pageId)->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/report/stock-adjustment')->exists())->toBeTrue();

    stkMenuMigration()->up();

    expect(DB::table('menus')->whereIn('route_path', $paths)->count())->toBe(2);
});
