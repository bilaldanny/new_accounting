<?php

use App\Http\Controllers\Reports\PartyReportController;
use App\Http\Controllers\Reports\ProductReportController;
use App\Http\Controllers\Reports\TransactionReportController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Item profit and loss (weighted average cost), the purchase & sale summary, the tax report, trending
 * products, and the access and menu rows of the nine product and tax reports.
 */

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function iptQuery(array $extra = []): array
{
    return array_merge(['show_record' => 100], $extra);
}

// ------------------------------------------------------------------ item profit and loss

test('a sale is costed at the weighted average purchase cost on the day it was made', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Costed');
    prdPurchase($scope, $product, 10, 100, '2026-08-01');
    prdPurchase($scope, $product, 10, 200, '2026-09-01');
    [, $early] = prdSale($scope, $product, 5, 300, '2026-08-15');
    [, $late] = prdSale($scope, $product, 4, 300, '2026-09-10');

    $response = prpGet('item-profit-loss', iptQuery());
    $first = prpRow($response, $early);
    $second = prpRow($response, $late);

    // Only the first purchase is known on 2026-08-15 (cost 100); both are on 2026-09-10 ((1000 + 2000) / 20 = 150).
    expect($first['average_cost'])->toEqual(100)
        ->and($first['cost'])->toEqual(500)
        ->and($first['net_amount'])->toEqual(1500)
        ->and($first['profit'])->toEqual(1000)
        ->and($first['margin'])->toEqual(66.67)
        ->and($second['average_cost'])->toEqual(150)
        ->and($second['cost'])->toEqual(600)
        ->and($second['profit'])->toEqual(600)
        ->and($second['margin'])->toEqual(50);
});

test('packing, purchase returns and drafts are taken into account in the average cost', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Packed');
    // 5 boxes of 10 at 20 per base unit = 50 base units, 1000; 2 boxes go back: 30 base units, 600.
    prdPurchase($scope, $product, 5, 20, '2026-08-01', [], ['packing_qty' => 10, 'quantity_returned' => 2]);
    // A draft purchase is not a cost.
    prdPurchase($scope, $product, 100, 1000, '2026-08-02', ['status' => 'draft']);
    [, $line] = prdSale($scope, $product, 2, 50, '2026-08-10', [], ['packing_qty' => 5]);

    $row = prpRow(prpGet('item-profit-loss', iptQuery()), $line);

    // 2 x 5 = 10 base units at 20 = 200 cost; 2 x 5 x 50 = 500 revenue.
    expect($row['average_cost'])->toEqual(20)
        ->and($row['net_base_quantity'])->toEqual(10)
        ->and($row['cost'])->toEqual(200)
        ->and($row['net_amount'])->toEqual(500)
        ->and($row['profit'])->toEqual(300);
});

test('a return on the sale takes its share off both the revenue and the cost', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Returned');
    prdPurchase($scope, $product, 100, 10, '2026-08-01');
    [, $line] = prdSale($scope, $product, 5, 30, '2026-08-10', [], ['quantity_returned' => 1]);

    $row = prpRow(prpGet('item-profit-loss', iptQuery()), $line);

    expect($row['net_amount'])->toEqual(120)
        ->and($row['cost'])->toEqual(40)
        ->and($row['profit'])->toEqual(80);
});

test('a variation never purchased has no cost and stays out of the cost and profit totals', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $known = prdProduct($scope, 'Known Cost');
    $unknown = prdProduct($scope, 'Unknown Cost');
    prdPurchase($scope, $known, 10, 10, '2026-08-01');
    [, $costed] = prdSale($scope, $known, 2, 30, '2026-08-10');
    [, $uncosted] = prdSale($scope, $unknown, 3, 100, '2026-08-10');
    // A purchase dated after the sale is not known on the day of the sale.
    prdPurchase($scope, $unknown, 10, 5, '2026-09-01');

    $response = prpGet('item-profit-loss', iptQuery());
    $row = prpRow($response, $uncosted);

    expect($row['average_cost'])->toBeNull()
        ->and($row['cost'])->toBeNull()
        ->and($row['profit'])->toBeNull()
        ->and($row['margin'])->toBeNull()
        ->and($row['net_amount'])->toEqual(300)
        ->and(prpRow($response, $costed)['profit'])->toEqual(40)
        ->and($response->json('summary'))->toMatchArray(['count' => 2, 'without_cost' => 1])
        ->and($response->json('summary.sales'))->toEqual(360)
        ->and($response->json('summary.cost'))->toEqual(20)
        ->and($response->json('summary.profit'))->toEqual(40)
        ->and($response->json('summary.margin'))->toEqual(66.67);
});

test('the cost is per company and drafts, quotations and other dates are handled like sales', function () {
    $mine = trpScope('1');
    $theirs = trpScope('2');
    trpActAsSuperadmin();

    $product = prdProduct($mine, 'Shared Name');
    prdPurchase($theirs, $product, 10, 999, '2026-08-01');
    prdPurchase($mine, $product, 10, 10, '2026-08-01');
    [, $line] = prdSale($mine, $product, 1, 30, '2026-08-10');
    [, $draft] = prdSale($mine, $product, 1, 30, '2026-08-10', ['status' => 'draft']);
    [, $quotation] = prdSale($mine, $product, 1, 30, '2026-08-10', ['status' => 'quotation']);

    $ids = prpRows(prpGet('item-profit-loss', iptQuery(['company_id' => $mine['company_id']])))->pluck('id')->all();

    expect($ids)->toBe([$line])
        ->and($ids)->not->toContain($draft)
        ->and($ids)->not->toContain($quotation)
        ->and(prpRow(prpGet('item-profit-loss', iptQuery(['company_id' => $mine['company_id']])), $line)['average_cost'])->toEqual(10);
});

// ------------------------------------------------------------------ purchase & sale summary

test('the purchase and sale summary takes returns off both sides', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $purchase = trpDoc($scope, 'purchaseorder', ['status' => 'received', 'transaction_date' => '2026-09-05', 'total_before_tax' => 900, 'tax_amount' => 100, 'final_amount' => 1000]);
    trpPayment($scope, $purchase, 300, '2026-09-06 10:00');
    trpDoc($scope, 'purchasereturn', ['transaction_date' => '2026-09-07', 'final_amount' => 200]);
    $sale = trpDoc($scope, 'sell', ['status' => 'final', 'transaction_date' => '2026-09-05', 'total_before_tax' => 1800, 'tax_amount' => 200, 'final_amount' => 2000]);
    trpPayment($scope, $sale, 500, '2026-09-06 10:00');
    $return = trpDoc($scope, 'sellreturn', ['transaction_date' => '2026-09-07', 'final_amount' => 300]);
    // A payment made on a return document is not a payment of the sale.
    trpPayment($scope, $return, 300, '2026-09-08 10:00');

    $response = prpGet('purchase-sale', iptQuery());
    $amount = fn (string $label): float => (float) prpRows($response)->firstWhere('label', $label)['amount'];

    expect($amount('Purchase documents'))->toBe(1.0)
        ->and($amount('Purchases before tax'))->toBe(900.0)
        ->and($amount('Purchase tax'))->toBe(100.0)
        ->and($amount('Purchases including tax'))->toBe(1000.0)
        ->and($amount('Purchase returns'))->toBe(200.0)
        ->and($amount('Net purchases'))->toBe(800.0)
        ->and($amount('Paid on purchases'))->toBe(300.0)
        ->and($amount('Purchase due'))->toBe(500.0)
        ->and($amount('Sales including tax'))->toBe(2000.0)
        ->and($amount('Sell returns'))->toBe(300.0)
        ->and($amount('Net sales'))->toBe(1700.0)
        ->and($amount('Received on sales'))->toBe(500.0)
        ->and($amount('Sales due'))->toBe(1200.0)
        ->and($amount('Net sales less net purchases'))->toBe(900.0)
        ->and($amount('Sales due less purchase due'))->toBe(700.0)
        ->and($response->json('summary'))->toMatchArray(['net_sales' => 1700, 'net_purchases' => 800, 'sales_due' => 1200, 'purchase_due' => 500, 'overall' => 900, 'overall_due' => 700]);
});

test('the purchase and sale summary counts documents by status and date like everything else', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    trpDoc($scope, 'sell', ['status' => 'draft', 'transaction_date' => '2026-09-05', 'final_amount' => 1]);
    trpDoc($scope, 'sell', ['status' => 'quotation', 'transaction_date' => '2026-09-05', 'final_amount' => 2]);
    trpDoc($scope, 'sell', ['status' => 'issue', 'transaction_date' => '2026-09-05', 'final_amount' => 4]);
    trpDoc($scope, 'sell', ['status' => 'final', 'transaction_date' => '2026-09-06 23:59:59', 'final_amount' => 8]);
    trpDoc($scope, 'sell', ['status' => 'final', 'transaction_date' => '2026-09-07 00:00:00', 'final_amount' => 16]);
    trpDoc($scope, 'sell', ['status' => 'final', 'transaction_date' => '2026-09-04 23:59:59', 'final_amount' => 32]);
    trpDoc($scope, 'sell', ['status' => 'final', 'transaction_date' => '2026-09-05', 'final_amount' => 64, 'deleted_at' => now()]);
    trpDoc($scope, 'purchaseorder', ['status' => 'draft', 'transaction_date' => '2026-09-05', 'final_amount' => 128]);
    trpDoc($scope, 'purchaseorder', ['status' => 'pending', 'transaction_date' => '2026-09-05', 'final_amount' => 256]);

    $response = prpGet('purchase-sale', iptQuery(['start_date' => '2026-09-05', 'end_date' => '2026-09-06']));

    expect($response->json('summary.net_sales'))->toEqual(12)
        ->and($response->json('summary.net_purchases'))->toEqual(256);
});

test('the purchase and sale summary is scoped to the company and branch and is empty without documents', function () {
    $mine = trpScope('1');
    $theirs = trpScope('2');
    $otherBranch = trpBranch($mine['company_id'], 'Second Branch');

    trpDoc($mine, 'sell', ['transaction_date' => '2026-09-05', 'final_amount' => 10]);
    trpDoc($mine, 'sell', ['branch_id' => $otherBranch, 'transaction_date' => '2026-09-05', 'final_amount' => 20]);
    trpDoc($theirs, 'sell', ['transaction_date' => '2026-09-05', 'final_amount' => 40]);

    Sanctum::actingAs(jeaUserWith($mine, ['/report/purchase-sale']));
    expect(prpGet('purchase-sale', iptQuery(['company_id' => $theirs['company_id']]))->json('summary.net_sales'))->toEqual(10);

    trpActAsSuperadmin();
    expect(prpGet('purchase-sale', iptQuery(['company_id' => $mine['company_id']]))->json('summary.net_sales'))->toEqual(30)
        ->and(prpGet('purchase-sale', iptQuery(['company_id' => $mine['company_id'], 'start_date' => '2030-01-01']))->json('summary.net_sales'))->toEqual(0);
});

// ------------------------------------------------------------------ tax

test('the tax report nets returns off each side and gives the net tax payable', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    trpDoc($scope, 'purchaseorder', ['status' => 'received', 'transaction_date' => '2026-09-05', 'tax_amount' => 10]);
    trpDoc($scope, 'purchaseorder', ['status' => 'received', 'transaction_date' => '2026-09-06', 'tax_amount' => 20]);
    trpDoc($scope, 'purchasereturn', ['transaction_date' => '2026-09-07', 'tax_amount' => 5]);
    trpDoc($scope, 'sell', ['status' => 'final', 'transaction_date' => '2026-09-05', 'tax_amount' => 40]);
    trpDoc($scope, 'sell', ['status' => 'final', 'transaction_date' => '2026-09-06', 'tax_amount' => 60]);
    trpDoc($scope, 'sellreturn', ['transaction_date' => '2026-09-07', 'tax_amount' => 10]);

    $response = prpGet('tax', iptQuery());

    expect($response->json('data.total'))->toBe(6)
        ->and($response->json('summary.input_tax'))->toEqual(25)
        ->and($response->json('summary.output_tax'))->toEqual(90)
        ->and($response->json('summary.net_tax'))->toEqual(65)
        ->and(prpRows($response)->where('side', 'input')->count())->toBe(3)
        ->and(prpRows($response)->pluck('document')->unique()->sort()->values()->all())->toBe(['Purchase', 'Purchase return', 'Sale', 'Sell return']);
});

test('the tax report filters by side, contact, date and search and leaves out drafts', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $other = prpContact($scope, 'customer', 'Tax Customer');
    $purchase = trpDoc($scope, 'purchaseorder', ['status' => 'received', 'transaction_date' => '2026-09-05', 'tax_amount' => 10, 'invoice_no' => 'PO-TAX']);
    $sale = trpDoc($scope, 'sell', ['status' => 'final', 'transaction_date' => '2026-09-06', 'tax_amount' => 20, 'contact_id' => $other]);
    trpDoc($scope, 'sell', ['status' => 'draft', 'transaction_date' => '2026-09-06', 'tax_amount' => 999]);
    trpDoc($scope, 'sell', ['status' => 'quotation', 'transaction_date' => '2026-09-06', 'tax_amount' => 999]);
    trpDoc($scope, 'purchaseorder', ['status' => 'draft', 'transaction_date' => '2026-09-06', 'tax_amount' => 999]);
    trpDoc($scope, 'sell', ['status' => 'final', 'transaction_date' => '2026-09-06', 'tax_amount' => 999, 'deleted_at' => now()]);
    trpDoc($scope, 'transfer', ['transaction_date' => '2026-09-06', 'tax_amount' => 999]);

    $ids = fn (array $extra): array => prpRows(prpGet('tax', iptQuery($extra)))->pluck('id')->sort()->values()->all();

    expect($ids([]))->toBe(collect([$purchase, $sale])->sort()->values()->all())
        ->and($ids(['tax_side' => 'input']))->toBe([$purchase])
        ->and($ids(['tax_side' => 'output']))->toBe([$sale])
        ->and($ids(['tax_side' => 'all']))->toBe(collect([$purchase, $sale])->sort()->values()->all())
        ->and($ids(['contact_id' => $other]))->toBe([$sale])
        ->and($ids(['start_date' => '2026-09-06']))->toBe([$sale])
        ->and($ids(['end_date' => '2026-09-05']))->toBe([$purchase])
        ->and($ids(['search' => 'PO-TAX']))->toBe([$purchase])
        ->and($ids(['search' => 'tax customer']))->toBe([$sale]);

    prpGet('tax', iptQuery(['tax_side' => 'both']))->assertUnprocessable();
});

// ------------------------------------------------------------------ trending products

test('trending products are the top sellers by base units sold, net of returns', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $a = prdProduct($scope, 'Product A');
    $b = prdProduct($scope, 'Product B');
    $c = prdProduct($scope, 'Product C');
    prdSale($scope, $a, 10, 1, '2026-09-05');
    prdSale($scope, $a, 5, 1, '2026-09-06', [], ['quantity_returned' => 3]);
    prdSale($scope, $b, 4, 100, '2026-09-05', [], ['packing_qty' => 6]);
    prdSale($scope, $c, 5, 1, '2026-09-05');
    // Outside the range, a draft and a quotation do not count.
    prdSale($scope, $c, 500, 1, '2026-08-01');
    prdSale($scope, $c, 500, 1, '2026-09-05', ['status' => 'draft']);
    prdSale($scope, $c, 500, 1, '2026-09-05', ['status' => 'quotation']);

    $range = ['start_date' => '2026-09-01', 'end_date' => '2026-09-30'];
    $top = prpRows(prpGet('trending-products', iptQuery($range + ['top' => 2])));
    $all = prpRows(prpGet('trending-products', iptQuery($range + ['top' => 10])));

    // B sold 4 x 6 = 24 base units, A 10 + (5 - 3) = 12, C 5.
    expect($top->pluck('product_name')->all())->toBe(['Product B', 'Product A'])
        ->and($top->pluck('rank')->all())->toBe([1, 2])
        ->and($top->first()['quantity'])->toEqual(24)
        ->and($top->last()['quantity'])->toEqual(12)
        ->and($all->pluck('product_name')->all())->toBe(['Product B', 'Product A', 'Product C'])
        ->and($all->last()['quantity'])->toEqual(5)
        ->and(prpGet('trending-products', iptQuery($range + ['top' => 2]))->json('summary'))->toMatchArray(['count' => 2])
        ->and(prpGet('trending-products', iptQuery($range + ['top' => 2]))->json('summary.quantity'))->toEqual(36);
});

test('trending products default to ten, break ties on the amount, and reject a bad top', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $cheap = prdProduct($scope, 'Cheap');
    $dear = prdProduct($scope, 'Dear');
    prdSale($scope, $cheap, 5, 1, '2026-09-05');
    prdSale($scope, $dear, 5, 100, '2026-09-05');
    foreach (range(1, 11) as $n) {
        prdSale($scope, prdProduct($scope, 'Extra '.$n), 1, 1, '2026-09-05');
    }

    $response = prpGet('trending-products', iptQuery());

    expect($response->json('data.total'))->toBe(10)
        ->and(prpRows($response)->pluck('product_name')->take(2)->all())->toBe(['Dear', 'Cheap']);

    prpGet('trending-products', iptQuery(['top' => 0]))->assertUnprocessable();
    prpGet('trending-products', iptQuery(['top' => 501]))->assertUnprocessable();
});

// ------------------------------------------------------------------ access and menu rows

dataset('product reports', [
    'product purchase' => ['product-purchase', '/report/product-purchase'],
    'product sell' => ['product-sell', '/report/product-sell'],
    'product sell summary' => ['product-sell-summary', '/report/product-sell-summary'],
    'item profit and loss' => ['item-profit-loss', '/report/item-profit-loss'],
    'item purchase' => ['item-purchase', '/report/item-purchase'],
    'item sell' => ['item-sell', '/report/item-sell'],
    'purchase and sale' => ['purchase-sale', '/report/purchase-sale'],
    'tax' => ['tax', '/report/tax'],
    'trending products' => ['trending-products', '/report/trending-products'],
]);

test('the report answers with a page of rows and a summary', function (string $report) {
    trpScope();
    trpActAsSuperadmin();

    $this->getJson("/api/reports/{$report}")
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => ['data', 'current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            'summary' => ['count'],
            'trash_count',
        ]);
})->with('product reports');

test('a guest is turned away and a user needs the menu row of the report', function (string $report, string $path) {
    $scope = trpScope();

    $this->getJson("/api/reports/{$report}")->assertUnauthorized();

    Sanctum::actingAs(jeaUserWith($scope, []));
    $this->getJson("/api/reports/{$report}")->assertForbidden();

    Sanctum::actingAs(jeaUserWith($scope, [$path]));
    $this->getJson("/api/reports/{$report}")->assertSuccessful();
})->with('product reports');

test('a page needs the same menu row and a signed in user', function (string $report, string $path) {
    $scope = trpScope();

    $this->get(route("report.{$report}"))->assertRedirect();

    $this->actingAs(User::query()->findOrFail(1))
        ->get(route("report.{$report}"))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('report/index')->where('report', $report));

    $this->actingAs(jeaUserWith($scope, []))->get(route("report.{$report}"))->assertForbidden();
    $this->actingAs(jeaUserWith($scope, [$path]))->get(route("report.{$report}"))->assertSuccessful();

    $match = app('router')->getRoutes()->match(Request::create($path, 'GET'));
    expect($match->getName())->toBe("report.{$report}");
})->with('product reports');

test('the permission map lists the nine reports and shares no name with the other report controllers', function () {
    expect(array_keys(ProductReportController::PERMISSIONS))->toBe([
        'product-purchase', 'product-sell', 'product-sell-summary', 'item-profit-loss', 'item-purchase', 'item-sell', 'purchase-sale', 'tax', 'trending-products',
    ])
        ->and(array_intersect_key(ProductReportController::PERMISSIONS, TransactionReportController::PERMISSIONS))->toBe([])
        ->and(array_intersect_key(ProductReportController::PERMISSIONS, PartyReportController::PERMISSIONS))->toBe([]);

    foreach (ProductReportController::PERMISSIONS as $report => $path) {
        expect($path)->toBe("/report/{$report}");
    }
});

function iptMenuMigration(): object
{
    return require database_path('migrations/2026_09_21_130000_add_product_report_menus.php');
}

test('the nine reports sit in the Reports group with a hidden export row, and the migration rolls back cleanly', function () {
    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Reports')->where('type', 2)->value('id');
    $paths = array_values(ProductReportController::PERMISSIONS);

    foreach ($paths as $path) {
        $page = DB::table('menus')->where('route_path', $path)->first();
        $export = DB::table('menus')->where('route_path', $path.'/export')->first();

        expect($page)->not->toBeNull()
            ->and((int) $page->parent_id)->toBe((int) $groupId)
            ->and((int) $page->is_hidden)->toBe(0)
            ->and($export)->not->toBeNull()
            ->and((int) $export->parent_id)->toBe((int) $page->id)
            ->and((int) $export->is_hidden)->toBe(1);
    }

    $before = DB::table('menus')->count();
    iptMenuMigration()->up();
    expect(DB::table('menus')->count())->toBe($before);

    $pageId = (int) DB::table('menus')->where('route_path', '/report/tax')->value('id');
    $role = Role::query()->create(['name' => 'accountant', 'is_active' => true]);
    Permission::query()->create(['role_id' => $role->id, 'menu_id' => $pageId, 'status' => 1]);

    iptMenuMigration()->down();

    expect(DB::table('menus')->whereIn('route_path', $paths)->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $pageId)->exists())->toBeFalse()
        ->and(DB::table('menus')->where('id', $groupId)->exists())->toBeTrue()
        ->and(DB::table('menus')->where('route_path', '/report/sell')->exists())->toBeTrue();

    iptMenuMigration()->up();

    expect(DB::table('menus')->whereIn('route_path', $paths)->count())->toBe(9);
});
