<?php

use App\Models\CashCollection;
use App\Models\Contact;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\PurchaseLine;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-15 12:00:00');
    Sanctum::actingAs(User::query()->findOrFail(1));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function arReport(string $report, array $query = []): array
{
    return test()->getJson("/api/reports/{$report}?".http_build_query(array_merge(['show_record' => 100], $query)))->assertSuccessful()->json();
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function arDoc(array $scope, string $type, float $amount, string $date, array $attributes = []): Transaction
{
    return Transaction::query()->create(array_merge([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'],
        'invoice_no' => strtoupper(substr($type, 0, 3)).'-'.fake()->unique()->numerify('######'), 'type' => $type, 'status' => 'final',
        'payment_status' => 'due', 'transaction_date' => $date.' 10:00:00', 'final_amount' => $amount, 'total_item' => 1,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $scope
 */
function arSellLine(array $scope, Transaction $sale, float $quantity, float $price, float $afterDiscount, ?int $productId = null): void
{
    DB::table('sell_lines')->insert([
        'transaction_id' => $sale->id, 'product_id' => $productId ?? $scope['product_id'], 'variation_id' => $scope['variation_id'],
        'unit_id' => $scope['unit_id'], 'quantity' => $quantity, 'unit_price' => $price, 'unit_price_after_discount' => $afterDiscount,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $scope
 */
function arPurchase(array $scope, string $date, float $quantity, float $rate, array $attributes = [], ?int $productId = null, ?int $variationId = null): Transaction
{
    $purchase = arDoc($scope, 'purchaseorder', $quantity * $rate, $date, array_merge(['status' => 'approved'], $attributes));

    PurchaseLine::query()->create([
        'transaction_id' => $purchase->id, 'product_id' => $productId ?? $scope['product_id'], 'variation_id' => $variationId ?? $scope['variation_id'],
        'itemtype_id' => $scope['itemtype_id'], 'unit_id' => $scope['unit_id'], 'quantity' => $quantity, 'purchase_rate' => $rate, 'packing_qty' => 1,
    ]);

    return $purchase;
}

test('the sales discount report adds line and invoice discounts and leaves undiscounted and unposted sales out', function () {
    $scope = seedSellScope();

    $a = arDoc($scope, 'sell', 162, '2026-09-10', ['discount_type' => 'percentage', 'discount_amount' => 10]);
    arSellLine($scope, $a, 2, 100, 90);
    $b = arDoc($scope, 'sell', 45, '2026-09-11', ['discount_type' => 'fixed', 'discount_amount' => 5]);
    arSellLine($scope, $b, 1, 50, 50);
    $none = arDoc($scope, 'sell', 100, '2026-09-12');
    arSellLine($scope, $none, 1, 100, 100);
    $draft = arDoc($scope, 'sell', 10, '2026-09-12', ['status' => 'draft', 'discount_type' => 'fixed', 'discount_amount' => 9]);
    arSellLine($scope, $draft, 1, 100, 100);

    $report = arReport('sales-discount', ['company_id' => $scope['company_id']]);
    $rows = collect($report['data']['data']);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['total_discount'])->toEqual(38)
        ->and($rows[0]['line_discount'])->toEqual(20)
        ->and($rows[0]['header_discount'])->toEqual(18)
        ->and($rows[0]['discount_percent'])->toEqual(19)
        ->and($rows[1]['total_discount'])->toEqual(5)
        ->and($report['summary']['total_discount'])->toEqual(43)
        ->and($report['summary']['gross'])->toEqual(250);
});

test('the purchase price trend gives each months weighted average and the change on the previous month', function () {
    $scope = seedPurchaseScope();
    arPurchase($scope, '2026-07-10', 10, 100);
    arPurchase($scope, '2026-07-20', 10, 110);
    arPurchase($scope, '2026-08-05', 5, 126);
    arPurchase($scope, '2026-08-06', 5, 999, ['status' => 'draft']);

    $rows = collect(arReport('purchase-price-trend', ['company_id' => $scope['company_id'], 'sort_by' => 'month', 'sort_type' => 'asc'])['data']['data']);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['month'])->toBe('2026-07')
        ->and($rows[0]['average_rate'])->toEqual(105)
        ->and($rows[0]['min_rate'])->toEqual(100)
        ->and($rows[0]['max_rate'])->toEqual(110)
        ->and($rows[0]['quantity'])->toEqual(20)
        ->and($rows[0]['change_percent'])->toBeNull()
        ->and($rows[1]['average_rate'])->toEqual(126)
        ->and($rows[1]['change_percent'])->toEqual(20);
});

test('suppliers are ranked by what was bought with returns, payments and what is outstanding', function () {
    $scope = seedPurchaseScope();
    $other = trpContact(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']], 'Second Supplier', 'supplier', 'SU-2');

    $one = arDoc($scope, 'purchaseorder', 1000, '2026-09-01', ['status' => 'approved']);
    arDoc($scope, 'purchasereturn', 100, '2026-09-05', ['status' => 'approved']);
    arDoc($scope, 'purchaseorder', 300, '2026-09-02', ['status' => 'approved', 'contact_id' => $other]);
    arDoc($scope, 'purchaseorder', 5000, '2026-09-02', ['status' => 'draft']);
    DB::table('payments')->insert(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'transaction_id' => $one->id, 'contact_id' => $scope['contact_id'], 'amount' => 400, 'method' => 'cash', 'created_at' => now(), 'updated_at' => now()]);

    $report = arReport('supplier-performance', ['company_id' => $scope['company_id']]);
    $rows = collect($report['data']['data']);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['rank'])->toBe(1)
        ->and($rows[0]['purchased'])->toEqual(1000)
        ->and($rows[0]['returned'])->toEqual(100)
        ->and($rows[0]['return_rate'])->toEqual(10)
        ->and($rows[0]['paid'])->toEqual(400)
        ->and($rows[0]['outstanding'])->toEqual(500)
        ->and($rows[1]['supplier_name'])->toBe('Second Supplier')
        ->and($report['summary']['purchased'])->toEqual(1300)
        ->and($report['summary']['outstanding'])->toEqual(800);
});

test('the backorder report lists only lines of unreceived orders that still have quantity pending', function () {
    $scope = seedPurchaseScope();
    $open = arPurchase($scope, '2026-09-01', 10, 5, ['invoice_no' => 'PO-OPEN']);
    $open->purchaselines()->update(['quantity_received' => 4]);
    arPurchase($scope, '2026-09-02', 10, 5, ['invoice_no' => 'PO-DONE', 'status' => 'received']);
    $full = arPurchase($scope, '2026-09-03', 10, 5, ['invoice_no' => 'PO-FULL']);
    $full->purchaselines()->update(['quantity_received' => 10]);

    $report = arReport('backorder', ['company_id' => $scope['company_id']]);
    $rows = collect($report['data']['data']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['invoice_no'])->toBe('PO-OPEN')
        ->and($rows[0]['pending'])->toEqual(6)
        ->and($rows[0]['days_open'])->toBe(14)
        ->and($report['summary']['pending'])->toEqual(6);
});

/**
 * @param  array<string, mixed>  $scope
 * @return array{product: int, variation: int}
 */
function arStockedProduct(array $scope, string $name, float $received): array
{
    $product = Product::query()->create([
        'company_id' => $scope['company_id'], 'unit_id' => $scope['unit_id'], 'name' => $name, 'sku' => 'SK-'.fake()->unique()->numerify('####'), 'type' => 'single', 'active' => true,
    ]);
    $detail = ProductDetail::query()->create([
        'product_id' => $product->id, 'name' => $name.' dummy', 'sku' => $product->sku.'-1', 'variation_name' => 'dummy', 'default_purchase_price' => 10,
        'dpp_unit_price' => 10, 'largequantity' => 1, 'smallquantity' => 1, 'profit_percent' => 10, 'default_sell_price' => 11,
    ]);

    $purchase = arPurchase($scope, '2026-05-01', $received, 10, ['status' => 'received'], $product->id, $detail->id);
    $purchase->purchaselines()->update(['quantity_received' => $received]);

    return ['product' => $product->id, 'variation' => $detail->id];
}

test('stock is classed fast, slow or non-moving by what sold in the period', function () {
    $scope = seedSellScope();
    $fast = arStockedProduct($scope, 'Fast Item', 50);
    $slow = arStockedProduct($scope, 'Slow Item', 50);
    arStockedProduct($scope, 'Dead Item', 7);

    $sale = arDoc($scope, 'sell', 100, '2026-09-01');
    arSellLine($scope, $sale, 10, 10, 10, $fast['product']);
    arSellLine($scope, $sale, 2, 10, 10, $slow['product']);

    $report = arReport('fsn', ['company_id' => $scope['company_id'], 'search' => 'Item']);
    $classes = collect($report['data']['data'])->pluck('class', 'product_name');

    expect($classes['Fast Item'])->toBe('Fast')
        ->and($classes['Slow Item'])->toBe('Slow')
        ->and($classes['Dead Item'])->toBe('Non-moving')
        ->and($report['summary']['non_moving'])->toBe(1)
        ->and($report['summary']['dead_stock_units'])->toEqual(7);
});

test('the cash collection report filters by status and totals what was collected', function () {
    $scope = seedSellScope();

    foreach ([['CC-1', 'completed', 500, '2026-09-10', 100], ['CC-2', 'pending', 200, '2026-09-11', 0], ['CC-3', 'cancelled', 50, '2026-09-12', 0], ['CC-OLD', 'completed', 999, '2026-05-01', 0]] as [$ref, $status, $amount, $date, $advance]) {
        CashCollection::query()->create([
            'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'],
            'reference' => $ref, 'collected_on' => $date, 'amount' => $amount, 'advance_amount' => $advance, 'status' => $status, 'collected_by' => 1,
        ]);
    }

    $all = arReport('cash-collection', ['company_id' => $scope['company_id']]);
    $completed = arReport('cash-collection', ['company_id' => $scope['company_id'], 'status' => 'completed']);

    expect($all['summary']['count'])->toBe(3)
        ->and($all['summary']['total'])->toEqual(750)
        ->and($all['summary']['pending'])->toEqual(200)
        ->and($all['summary']['advance'])->toEqual(100)
        ->and($completed['data']['data'])->toHaveCount(1)
        ->and($completed['data']['data'][0]['reference'])->toBe('CC-1');
});

test('payments are grouped by payment account and method and by their age', function () {
    $scope = seedSellScope();
    $cash = ldgAccount($scope, '202-00001', 'Cash Drawer', 't', 'dr');
    $bank = ldgAccount($scope, '202-00002', 'Main Bank', 't', 'dr');

    $young = arDoc($scope, 'sell', 100, '2026-09-01');
    $old = arDoc($scope, 'sell', 200, '2026-06-01');
    $purchase = arDoc($scope, 'purchaseorder', 300, '2026-08-01', ['status' => 'approved']);

    foreach ([[$young, 100, 'cash', $cash, 0], [$old, 150, 'cash', $cash, 0], [$old, 50, 'bank_transfer', $bank, 0], [$purchase, 80, 'bank_transfer', $bank, 0], [$young, 10, 'cash', $cash, 1]] as [$doc, $amount, $method, $account, $return]) {
        DB::table('payments')->insert([
            'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'transaction_id' => $doc->id, 'contact_id' => $scope['contact_id'],
            'amount' => $amount, 'method' => $method, 'payment_account' => $account, 'is_return' => $return, 'created_at' => '2026-09-10 09:00:00', 'updated_at' => now(),
        ]);
    }

    $accounts = arReport('payment-account', ['company_id' => $scope['company_id']]);
    $byKey = collect($accounts['data']['data'])->keyBy(fn (array $row): string => $row['account_code'].'|'.$row['method']);

    expect($byKey['202-00001|cash']['received'])->toEqual(240)
        ->and($byKey['202-00002|bank_transfer']['received'])->toEqual(50)
        ->and($byKey['202-00002|bank_transfer']['paid'])->toEqual(80)
        ->and($accounts['summary']['received'])->toEqual(290)
        ->and($accounts['summary']['net'])->toEqual(210);

    $age = arReport('payment-age', ['company_id' => $scope['company_id']]);
    $buckets = collect($age['data']['data'])->keyBy(fn (array $row): string => $row['direction'].'|'.$row['bucket']);

    // young invoice: 9 days (100 less the 10 returned); old invoice: 101 days (150 + 50); purchase: 40 days
    expect($buckets['received|0-30 days']['amount'])->toEqual(90)
        ->and($buckets['received|Over 90 days']['amount'])->toEqual(200)
        ->and($buckets['paid|31-60 days']['amount'])->toEqual(80)
        ->and($age['summary']['received_over_60'])->toEqual(200);
});

test('the consolidated report puts each branch side by side and needs a company', function () {
    $scope = trpScope('K');
    $second = trpBranch($scope['company_id'], 'Second Branch');
    arDoc($scope + ['contact_id' => $scope['customer_id']], 'sell', 1000, '2026-09-10');
    arDoc(['company_id' => $scope['company_id'], 'branch_id' => $second, 'contact_id' => $scope['customer_id']], 'sell', 400, '2026-09-11');
    arDoc($scope + ['contact_id' => $scope['supplier_id']], 'purchaseorder', 250, '2026-09-12', ['status' => 'approved']);

    $report = arReport('consolidated-branch', ['company_id' => $scope['company_id']]);
    $rows = collect($report['data']['data'])->keyBy('branch_name');

    expect($rows)->toHaveCount(2)
        ->and($rows['Report Branch K']['net_sales'])->toEqual(1000)
        ->and($rows['Report Branch K']['net_purchases'])->toEqual(250)
        ->and($rows['Second Branch']['net_sales'])->toEqual(400)
        ->and($report['summary']['net_sales'])->toEqual(1400)
        ->and($report['summary']['result'])->toEqual(1150);

    $none = arReport('consolidated-branch');

    expect($none['needs_company'])->toBeTrue()->and($none['data']['data'])->toBe([]);
});

/**
 * Cash and bank with a mapped cash account, then: +1000 sale, -200 expense, +500 owner capital, -300 for a vehicle.
 *
 * @return array{company_id: int, branch_id: int}
 */
function arCashBooks(): array
{
    $scope = trpScope('F');
    $cash = ldgAccount($scope, '202-00001', 'Cash Drawer', 't', 'dr');
    ldgAccount($scope, '501-00001', 'Sales', 't', 'cr');
    ldgAccount($scope, '401-00001', 'Rent', 't', 'dr');
    ldgAccount($scope, '101-00001', 'Owner Capital', 't', 'cr');
    ldgAccount($scope, '202-00009', 'Delivery Vehicle', 't', 'dr');
    DB::table('chart_of_account_mappings')->insert(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'name' => 'Cash', 'key' => 'cash', 'value' => $cash, 'created_at' => now(), 'updated_at' => now()]);

    ldgVoucher($scope, 'R1', '2026-09-02', [['202-00001', 1000, 0], ['501-00001', 0, 1000]]);
    ldgVoucher($scope, 'P1', '2026-09-03', [['401-00001', 200, 0], ['202-00001', 0, 200]]);
    ldgVoucher($scope, 'C1', '2026-09-04', [['202-00001', 500, 0], ['101-00001', 0, 500]]);
    ldgVoucher($scope, 'V1', '2026-09-05', [['202-00009', 300, 0], ['202-00001', 0, 300]]);
    ldgVoucher($scope, 'P2', '2026-09-06', [['401-00001', 999, 0], ['202-00001', 0, 999]], ['status' => 'pending']);

    return $scope;
}

test('the cash flow statement sorts the cash movement into operating, investing and financing', function () {
    $scope = arCashBooks();

    $report = arReport('cash-flow', ['company_id' => $scope['company_id'], 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
    $sections = collect($report['data']['data'])->groupBy('section')->map(fn ($rows) => round((float) $rows->sum('net'), 2));

    expect($sections['Operating'])->toEqual(800)
        ->and($sections['Investing'])->toEqual(-300)
        ->and($sections['Financing'])->toEqual(500)
        ->and($report['summary']['net_change'])->toEqual(1000)
        ->and($report['summary']['opening_cash'])->toEqual(0)
        ->and($report['summary']['closing_cash'])->toEqual(1000);
});

test('the financial ratios come from the balance sheet and the profit and loss', function () {
    $scope = arCashBooks();

    $report = arReport('financial-ratios', ['company_id' => $scope['company_id'], 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
    $ratios = collect($report['data']['data'])->pluck('value', 'label');

    expect($ratios)->toHaveCount(8)
        ->and($ratios['Gross margin'])->toEqual(100)
        ->and($ratios['Net margin'])->toEqual(80)
        ->and($ratios['Expense ratio'])->toEqual(20)
        ->and($ratios['Debt ratio'])->toEqual(0)
        ->and($report['summary']['net_margin'])->toEqual(80);
});

test('the register report lists each shift with its variance and totals the shortages', function () {
    $scope = seedSellScope();

    foreach ([['closed', 100, 90, -10, '2026-09-10 08:00:00'], ['closed', 100, 130, 30, '2026-09-11 08:00:00'], ['open', 50, null, null, '2026-09-14 08:00:00']] as [$status, $float, $counted, $variance, $opened]) {
        PosShift::query()->create([
            'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'user_id' => 1, 'opening_float' => $float, 'opened_at' => $opened,
            'status' => $status, 'closed_at' => $status === 'closed' ? $opened : null, 'expected_cash' => $counted === null ? null : $counted - $variance,
            'counted_cash' => $counted, 'variance' => $variance,
            'summary' => $status === 'closed' ? ['sales_count' => 3, 'sales_total' => 300, 'cash_payments' => 250, 'expected_cash' => $counted - $variance] : null,
        ]);
    }

    $report = arReport('register', ['company_id' => $scope['company_id']]);

    expect($report['summary']['shifts'])->toBe(3)
        ->and($report['summary']['open'])->toBe(1)
        ->and($report['summary']['variance'])->toEqual(20)
        ->and($report['summary']['short_shifts'])->toBe(1)
        ->and($report['summary']['sales_total'])->toEqual(600);

    expect(arReport('register', ['company_id' => $scope['company_id'], 'status' => 'open'])['data']['data'])->toHaveCount(1);
});

test('the activity summary counts what each user did and the change history lists old and new values', function () {
    $scope = seedSellScope();
    $contact = Contact::query()->findOrFail($scope['contact_id']);
    $before = collect(arReport('activity-summary', ['company_id' => $scope['company_id'], 'search' => 'Contact'])['data']['data'])->firstWhere('model', 'Contact')['updated'];
    $contact->update(['business_name' => 'Acme Wholesale']);
    $contact->update(['mobile' => '03111111111']);

    $activity = arReport('activity-summary', ['company_id' => $scope['company_id'], 'search' => 'Contact']);
    $row = collect($activity['data']['data'])->firstWhere('model', 'Contact');

    expect($row['updated'])->toBe($before + 2)->and($row['created'])->toBeGreaterThanOrEqual(1);

    $history = collect(arReport('change-history', ['company_id' => $scope['company_id'], 'search' => 'business_name'])['data']['data']);
    $change = $history->firstWhere('field', 'business_name');

    expect($change['old_value'])->toBe('Acme Retail')
        ->and($change['new_value'])->toBe('Acme Wholesale')
        ->and($change['model'])->toBe('Contact')
        ->and($change['event'])->toBe('updated');

    $byRecord = collect(arReport('change-history', ['company_id' => $scope['company_id'], 'search' => (string) $contact->id])['data']['data']);

    expect($byRecord->pluck('record_id')->unique()->all())->toBe([$contact->id]);
});

test('unknown reports, missing permission and company isolation are enforced', function () {
    $scope = seedSellScope();

    $this->getJson('/api/reports/not-a-report')->assertNotFound();

    $role = Role::query()->create(['name' => 'accountant', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));

    $this->getJson('/api/reports/sales-discount')->assertForbidden();
    $this->getJson('/api/reports/change-history')->assertForbidden();

    grantMenuPermission($role->id, '/report/change-history', 'changehistory'.uniqid());

    $this->getJson('/api/reports/change-history')->assertSuccessful();

    $other = trpScope('Z');
    Sanctum::actingAs(createStaffUserForRole(tap(Role::query()->create(['name' => 'accountant2', 'company_id' => $other['company_id'], 'is_active' => true]), fn (Role $r) => grantMenuPermission($r->id, '/report/change-history', 'ch2'.uniqid())), ['company_id' => $other['company_id'], 'branch_id' => $other['branch_id']]));

    expect(collect($this->getJson('/api/reports/change-history?show_record=100')->json('data.data'))->where('model', 'Contact')->where('new_value', 'Acme Retail'))->toBeEmpty();
});

test('the menu migration adds the fourteen reports next to the stock report and grants companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $group = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Reports', 'icon' => '', 'route_name' => 'rgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('menus')->insert([
        'parent_id' => $group, 'name' => 'Stock', 'icon' => '', 'route_name' => 'report.stock', 'route_path' => '/report/stock', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $migration = require database_path('migrations/2026_10_09_100000_add_analytics_report_menus.php');
    $migration->up();
    $migration->up();

    expect(DB::table('menus')->where('parent_id', $group)->count())->toBe(15)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(14);

    $migration->down();

    expect(DB::table('menus')->where('parent_id', $group)->count())->toBe(1);
});
