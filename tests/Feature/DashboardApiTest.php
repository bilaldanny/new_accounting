<?php

use App\Http\Controllers\DashboardController;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The dashboard cards. Documents are inserted straight into the tables (the transaction / party / product report
 * helpers) so every amount, status and date is known, and "today" is fixed to 15 September 2026.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-15 12:00:00');
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $query
 */
function dshGet(string $widget, array $query = []): TestResponse
{
    return test()->getJson('/api/dashboard/'.$widget.($query === [] ? '' : '?'.http_build_query($query)));
}

/**
 * The parts a widget answered with.
 *
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function dshData(string $widget, array $query = []): array
{
    return dshGet($widget, $query)->assertSuccessful()->json('data');
}

/**
 * A user of the scope's company whose role holds exactly these menu paths.
 *
 * @param  array<string, mixed>  $scope
 * @param  list<string>  $paths
 * @param  array<string, mixed>  $attributes
 */
function dshUser(array $scope, array $paths, array $attributes = []): User
{
    $role = Role::query()->create(['name' => 'accountant'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path, ltrim(str_replace('/', '', $path), '/').uniqid());
    }

    return createStaffUserForRole($role, array_merge(['company_id' => $scope['company_id']], $attributes));
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function dshDoc(array $scope, string $type, float $amount, string $date, array $attributes = []): int
{
    return trpDoc($scope, $type, array_merge(['final_amount' => $amount, 'transaction_date' => $date.' 10:00:00'], $attributes));
}

// --- sales and purchases -------------------------------------------------------------------------

test('the sales card gives today, the month and the change against the same days of last month', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();

    dshDoc($scope, 'sell', 1000, '2026-09-15');
    dshDoc($scope, 'sell', 5000, '2026-09-15', ['status' => 'draft']);
    dshDoc($scope, 'sell', 700, '2026-09-15', ['status' => 'quotation']);
    dshDoc($scope, 'sellreturn', 100, '2026-09-15');
    dshDoc($scope, 'sell', 500, '2026-09-03', ['status' => 'approved']);
    dshDoc($scope, 'sell', 700, '2026-08-10');
    dshDoc($scope, 'sell', 300, '2026-08-20');
    dshDoc($scope, 'sell', 4000, '2026-09-16');

    $sales = dshData('sales', ['company_id' => $scope['company_id']])['sales'];

    expect($sales['today'])->toEqual(900)
        ->and($sales['this_month'])->toEqual(1400)
        ->and($sales['last_month'])->toEqual(1000)
        ->and($sales['last_month_to_date'])->toEqual(700)
        ->and($sales['as_of'])->toBe('2026-09-15')
        ->and($sales['month_start'])->toBe('2026-09-01');
});

test('the change is the month so far against the same days of last month, and empty when last month had nothing', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();

    dshDoc($scope, 'sell', 1400, '2026-09-04');
    dshDoc($scope, 'sell', 700, '2026-08-10');
    dshDoc($scope, 'sell', 300, '2026-08-20');

    expect(dshData('sales', ['company_id' => $scope['company_id']])['sales']['change_percent'])->toEqual(100.0);

    DB::table('transactions')->where('transaction_date', 'like', '2026-08%')->delete();

    expect(dshData('sales', ['company_id' => $scope['company_id']])['sales']['change_percent'])->toBeNull();

    dshDoc($scope, 'sell', 2000, '2026-08-05');
    dshDoc($scope, 'sell', 1000, '2026-08-05', ['status' => 'draft']);

    expect(dshData('sales', ['company_id' => $scope['company_id']])['sales']['change_percent'])->toEqual(-30.0);
});

test('on the last day of a longer month the same days of last month stop at its end', function () {
    Carbon::setTestNow('2026-03-31 09:00:00');
    $scope = trpScope('A');
    trpActAsSuperadmin();

    dshDoc($scope, 'sell', 200, '2026-02-28');
    dshDoc($scope, 'sell', 300, '2026-02-01');
    dshDoc($scope, 'sell', 100, '2026-03-31');

    $sales = dshData('sales', ['company_id' => $scope['company_id']])['sales'];

    expect($sales['last_month'])->toEqual(500)
        ->and($sales['last_month_to_date'])->toEqual(500)
        ->and($sales['this_month'])->toEqual(100)
        ->and($sales['change_percent'])->toEqual(-80.0);
});

test('the purchase card is the month\'s purchases net of returns, drafts left out', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();

    dshDoc($scope, 'purchaseorder', 800, '2026-09-02', ['status' => 'received']);
    dshDoc($scope, 'purchaseorder', 200, '2026-09-05', ['status' => 'pending']);
    dshDoc($scope, 'purchaseorder', 999, '2026-09-06', ['status' => 'draft']);
    dshDoc($scope, 'purchasereturn', 100, '2026-09-07');
    dshDoc($scope, 'purchaseorder', 450, '2026-08-05', ['status' => 'received']);
    dshDoc($scope, 'purchaseorder', 250, '2026-08-25', ['status' => 'received']);

    $purchases = dshData('sales', ['company_id' => $scope['company_id']])['purchases'];

    expect($purchases['this_month'])->toEqual(900)
        ->and($purchases['last_month'])->toEqual(700)
        ->and($purchases['last_month_to_date'])->toEqual(450)
        ->and($purchases['change_percent'])->toEqual(100.0);
});

// --- what customers owe, what suppliers are owed, credit limits ----------------------------------

/**
 * Five customers and a supplier with known ledger balances.
 *
 * @param  array<string, mixed>  $scope
 * @return array<string, int>
 */
function dshLedger(array $scope): array
{
    $near = prpContact($scope, 'customer', 'Near Limit', ['credit_limit' => 800]);
    $over = prpContact($scope, 'customer', 'Over Limit', ['credit_limit' => 1500]);
    $free = prpContact($scope, 'customer', 'No Limit', ['credit_limit' => 0]);
    $advance = prpContact($scope, 'customer', 'Paid Ahead', ['credit_limit' => 100]);
    $low = prpContact($scope, 'customer', 'Low Use', ['credit_limit' => 10000]);
    $supplier = prpContact($scope, 'supplier', 'Creditor');

    $invoice = dshDoc($scope, 'sell', 1000, '2026-09-01', ['contact_id' => $near]);
    trpPayment($scope, $invoice, 300, '2026-09-02 10:00');
    dshDoc($scope, 'sell', 2000, '2026-09-01', ['contact_id' => $over]);
    dshDoc($scope, 'sell', 100, '2026-09-01', ['contact_id' => $free]);
    $paidAhead = dshDoc($scope, 'sell', 100, '2026-09-01', ['contact_id' => $advance]);
    trpPayment($scope, $paidAhead, 150, '2026-09-02 10:00');
    dshDoc($scope, 'sell', 500, '2026-09-01', ['contact_id' => $low]);
    dshDoc($scope, 'sell', 900, '2026-09-01', ['contact_id' => $low, 'status' => 'draft']);

    $purchase = dshDoc($scope, 'purchaseorder', 2000, '2026-09-01', ['contact_id' => $supplier, 'status' => 'received']);
    dshDoc($scope, 'purchasereturn', 200, '2026-09-02', ['contact_id' => $supplier, 'parent_id' => $purchase]);
    trpPayment($scope, $purchase, 800, '2026-09-03 10:00');

    return compact('near', 'over', 'free', 'advance', 'low', 'supplier');
}

test('receivables and payables are what the contact ledgers say', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();
    dshLedger($scope);

    $data = dshData('receivables', ['company_id' => $scope['company_id']]);

    expect($data['receivables']['total_due'])->toEqual(700 + 2000 + 100 + 500)
        ->and($data['receivables']['advances'])->toEqual(50)
        ->and($data['receivables']['contacts'])->toBe(5)
        ->and($data['receivables']['as_of'])->toBe('2026-09-15')
        ->and($data['payables']['total_due'])->toEqual(1000)
        ->and($data['payables']['contacts'])->toBe(1);
});

test('the credit watch lists the customers at 80% of their limit or over it, the most used first, and ignores no limit', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();
    $ids = dshLedger($scope);

    $watch = dshData('receivables', ['company_id' => $scope['company_id']])['credit_watch'];

    expect($watch['exceeded'])->toBe(1)
        ->and($watch['near'])->toBe(1)
        ->and(array_column($watch['items'], 'name'))->toBe(['Over Limit', 'Near Limit'])
        ->and($watch['items'][0])->toMatchArray(['id' => $ids['over'], 'balance' => 2000.0, 'credit_limit' => 1500.0, 'used_percent' => 133.3, 'exceeded' => true])
        ->and($watch['items'][1])->toMatchArray(['id' => $ids['near'], 'balance' => 700.0, 'credit_limit' => 800.0, 'used_percent' => 87.5, 'exceeded' => false]);
});

test('a customer exactly at 80% of the limit is watched and one just under is not', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();
    $edge = prpContact($scope, 'customer', 'Edge', ['credit_limit' => 1000]);
    $under = prpContact($scope, 'customer', 'Under', ['credit_limit' => 1000]);
    dshDoc($scope, 'sell', 800, '2026-09-01', ['contact_id' => $edge]);
    dshDoc($scope, 'sell', 799.99, '2026-09-01', ['contact_id' => $under]);

    $watch = dshData('receivables', ['company_id' => $scope['company_id']])['credit_watch'];

    expect(array_column($watch['items'], 'name'))->toBe(['Edge']);
});

test('the receivables of a company never include another company\'s customers', function () {
    $mine = trpScope('A');
    $theirs = trpScope('B');
    trpActAsSuperadmin();
    dshLedger($mine);
    dshDoc($theirs, 'sell', 99999, '2026-09-01');

    $data = dshData('receivables', ['company_id' => $mine['company_id']]);

    expect($data['receivables']['total_due'])->toEqual(3300);
});

// --- inventory -----------------------------------------------------------------------------------

test('the inventory card counts the products at or under their alert quantity and values the stock on hand', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();

    $low = prdProduct($scope, 'Low Bolt', ['alert_qty' => 10]);
    prdPurchase($scope, $low, 4, 50, '2026-09-01');
    $fine = prdProduct($scope, 'Plenty Nut', ['alert_qty' => 5]);
    prdPurchase($scope, $fine, 20, 100, '2026-09-01');
    $noAlert = prdProduct($scope, 'No Alert', ['alert_qty' => null]);
    prdPurchase($scope, $noAlert, 1, 999, '2026-09-01');
    $inactive = prdProduct($scope, 'Inactive', ['alert_qty' => 10, 'active' => 0]);
    prdPurchase($scope, $inactive, 1, 10, '2026-09-01');

    $foreign = trpScope('B');
    prdPurchase($foreign, prdProduct($foreign, 'Foreign', ['alert_qty' => 100]), 1, 1000, '2026-09-01');

    $data = dshData('inventory', ['company_id' => $scope['company_id']]);

    expect($data['low_stock']['count'])->toBe(1)
        ->and($data['low_stock']['items'])->toHaveCount(1)
        ->and($data['low_stock']['items'][0])->toMatchArray(['name' => 'Low Bolt', 'stock' => 4.0, 'alert_qty' => 10.0])
        ->and($data['stock_value']['value'])->toEqual(200 + 2000 + 999 + 10)
        ->and($data['stock_value']['as_of'])->toBe('2026-09-15');
});

test('the low stock count and list agree with the Low Stock page', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();
    foreach (range(1, 12) as $n) {
        prdPurchase($scope, prdProduct($scope, 'Item '.$n, ['alert_qty' => 100]), $n, 10, '2026-09-01');
    }

    $data = dshData('inventory', ['company_id' => $scope['company_id']])['low_stock'];
    $page = test()->getJson('/api/lowstock?company_id='.$scope['company_id'].'&show_record=100')->assertSuccessful();

    expect($data['count'])->toBe(12)
        ->and($data['count'])->toBe(count($page->json('data.data')))
        ->and($data['items'])->toHaveCount(8)
        ->and($data['items'][0]['name'])->toBe('Item 1');
});

// --- approvals -----------------------------------------------------------------------------------

test('the approvals card counts what is waiting on each approval page', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();

    foreach (['pending', 'pending', 'received', 'draft'] as $status) {
        dshDoc($scope, 'purchaseorder', 100, '2026-09-01', ['status' => $status]);
    }

    foreach (['final', 'final', 'final', 'approved', 'draft', 'quotation'] as $status) {
        dshDoc($scope, 'sell', 100, '2026-09-01', ['status' => $status]);
    }

    $voucher = 0;
    $make = function (string $prefix, string $status, int $times = 1) use ($scope, &$voucher): void {
        foreach (range(1, $times) as $ignored) {
            $voucher++;
            ldgVoucher($scope, $prefix.'-'.$voucher, '2026-09-10', [['111-00001', 100, 0], ['511-00001', 0, 100]], ['status' => $status]);
        }
    };
    $make('JV', 'pending');
    $make('JV', 'approved', 2);
    $make('BP', 'pending', 2);
    $make('CP', 'pending');
    $make('EXP', 'pending');
    $make('EXP', 'rejected', 4);
    $make('BD', 'pending', 4);
    $make('FT', 'pending', 3);

    $other = trpScope('B');
    dshDoc($other, 'purchaseorder', 100, '2026-09-01', ['status' => 'pending']);
    dshDoc($other, 'sell', 100, '2026-09-01', ['status' => 'final']);
    ldgVoucher($other, 'JV-900', '2026-09-10', [['111-00001', 100, 0], ['511-00001', 0, 100]], ['status' => 'pending']);

    $counts = dshData('approvals', ['company_id' => $scope['company_id']]);

    expect($counts)->toBe(['purchase' => 2, 'sell' => 3, 'journal' => 1, 'payment' => 3, 'expense' => 1, 'deposit' => 4, 'fundtransfer' => 3]);
});

// --- net profit, cash and bank -------------------------------------------------------------------

/**
 * A company with a financial year, cash and bank accounts and a month of approved vouchers.
 * Cash: 1000 opening + 2000 (August sale) + 5000 (September sale); bank: -800 (rent). A pending voucher counts for nothing.
 *
 * @return array<string, mixed>
 */
function dshBooks(string $suffix = 'F'): array
{
    $scope = trpScope($suffix);
    prpFinancialYear($scope['company_id']);

    $cash = ldgAccount($scope, '211-00001', 'Cash in Hand');
    $bank = ldgAccount($scope, '211-00002', 'Main Bank');
    ldgAccount($scope, '511-00001', 'Sales', 't', 'cr');
    ldgAccount($scope, '411-00001', 'Rent', 't', 'dr');
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cash);
    insertPurchaseAccountMapping($scope, 'Bank', 'bank', $bank);
    prpOpeningBalance($scope, $cash, 1000, 'dr');

    ldgVoucher($scope, 'JV-101', '2026-08-20', [['211-00001', 2000, 0], ['511-00001', 0, 2000]]);
    ldgVoucher($scope, 'JV-102', '2026-09-03', [['211-00001', 5000, 0], ['511-00001', 0, 5000]]);
    ldgVoucher($scope, 'JV-103', '2026-09-08', [['411-00001', 800, 0], ['211-00002', 0, 800]]);
    ldgVoucher($scope, 'JV-104', '2026-09-09', [['211-00001', 999, 0], ['511-00001', 0, 999]], ['status' => 'pending']);

    return $scope;
}

test('the financial card gives the month\'s net profit and the cash and bank balance', function () {
    $scope = dshBooks();
    trpActAsSuperadmin();

    $data = dshData('financial', ['company_id' => $scope['company_id']]);

    expect($data['net_profit']['net_profit'])->toEqual(5000 - 800)
        ->and($data['net_profit']['revenue'])->toEqual(5000)
        ->and($data['net_profit']['expenses'])->toEqual(800)
        ->and($data['net_profit']['from'])->toBe('2026-09-01')
        ->and($data['net_profit']['to'])->toBe('2026-09-15')
        ->and($data['cash_bank']['total'])->toEqual(1000 + 2000 + 5000 - 800)
        ->and($data['cash_bank']['accounts'])->toEqual([
            ['code' => '211-00001', 'name' => 'Cash in Hand', 'balance' => 8000],
            ['code' => '211-00002', 'name' => 'Main Bank', 'balance' => -800],
        ]);
});

test('the net profit is the Profit and Loss report over the month so far', function () {
    $scope = dshBooks();
    trpActAsSuperadmin();

    $report = test()->getJson('/api/reports/profit-loss?company_id='.$scope['company_id'].'&start_date=2026-09-01&end_date=2026-09-15')->assertSuccessful();

    expect(dshData('financial', ['company_id' => $scope['company_id']])['net_profit']['net_profit'])->toEqual($report->json('summary.net_profit'));
});

test('cost of goods sold is taken off the month\'s profit, as the Profit and Loss report does', function () {
    $scope = dshBooks();
    trpActAsSuperadmin();
    ldgAccount($scope, '611-00001', 'Purchases', 't', 'dr');
    ldgVoucher($scope, 'JV-105', '2026-09-05', [['611-00001', 1000, 0], ['211-00001', 0, 1000]]);
    prdPurchase($scope, prdProduct($scope, 'Stocked'), 5, 100, '2026-09-05');

    $profit = dshData('financial', ['company_id' => $scope['company_id']])['net_profit'];

    // revenue 5000, purchases 1000, closing stock 500 (5 x 100), opening 0 => cogs 500; expenses 800
    expect($profit['net_profit'])->toEqual(5000 - 500 - 800);
});

test('the cash and bank balance ignores accounts that are not cash or bank and other companies', function () {
    $scope = dshBooks();
    ldgAccount($scope, '211-00050', 'Receivable Control');
    ldgVoucher($scope, 'JV-106', '2026-09-09', [['211-00050', 700, 0], ['511-00001', 0, 700]]);
    $other = dshBooks('G');
    trpActAsSuperadmin();

    $cash = dshData('financial', ['company_id' => $scope['company_id']])['cash_bank'];

    expect($cash['total'])->toEqual(7200)
        ->and(array_column($cash['accounts'], 'code'))->toBe(['211-00001', '211-00002'])
        ->and(dshData('financial', ['company_id' => $other['company_id']])['cash_bank']['total'])->toEqual(7200);
});

// --- quick stats and recent sales ----------------------------------------------------------------

test('the quick stats count active customers, suppliers and products of the company', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();
    $before = dshData('stats', ['company_id' => $scope['company_id']]);

    prpContact($scope, 'customer', 'Active Customer');
    prpContact($scope, 'customer', 'Second Customer');
    prpContact($scope, 'both', 'Both Ways');
    prpContact($scope, 'customer', 'Inactive Customer', ['active' => false]);
    prpContact($scope, 'customer', 'Deleted Customer')
        ? Contact::query()->where('business_name', 'Deleted Customer')->delete()
        : null;
    prpContact($scope, 'supplier', 'Active Supplier');

    foreach (range(1, 4) as $n) {
        prdProduct($scope, 'Active '.$n);
    }
    prdProduct($scope, 'Off', ['active' => 0]);
    prdProduct($scope, 'Gone', ['deleted_at' => now()]);

    $other = trpScope('B');
    prpContact($other, 'customer', 'Foreign Customer');
    prdProduct($other, 'Foreign Product');

    $after = dshData('stats', ['company_id' => $scope['company_id']]);

    expect($after['customers'] - $before['customers'])->toBe(3)
        ->and($after['suppliers'] - $before['suppliers'])->toBe(2)
        ->and($after['products'] - $before['products'])->toBe(4);
});

test('the recent sales are the latest eight of the company, newest first, with the customer\'s name', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();
    $named = prpContact($scope, 'customer', 'Named Customer');
    $person = prpContact($scope, 'customer', '', ['first_name' => 'Sara', 'last_name' => 'Khan']);

    foreach (range(1, 10) as $n) {
        dshDoc($scope, 'sell', $n * 10, '2026-09-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), ['invoice_no' => 'RS-'.$n, 'contact_id' => $n === 10 ? $named : ($n === 9 ? $person : $scope['customer_id'])]);
    }
    dshDoc($scope, 'sell', 5, '2026-09-11', ['invoice_no' => 'RS-DRAFT', 'status' => 'draft']);
    dshDoc(trpScope('B'), 'sell', 77, '2026-09-12', ['invoice_no' => 'RS-OTHER']);

    $recent = dshData('recent', ['company_id' => $scope['company_id']])['recent_sales'];

    expect($recent)->toHaveCount(8)
        ->and(array_column($recent, 'invoice_no'))->toBe(['RS-DRAFT', 'RS-10', 'RS-9', 'RS-8', 'RS-7', 'RS-6', 'RS-5', 'RS-4'])
        ->and($recent[0])->toMatchArray(['status' => 'draft', 'amount' => 5.0])
        ->and($recent[1])->toMatchArray(['customer' => 'Named Customer', 'amount' => 100.0, 'date' => '2026-09-10'])
        ->and($recent[2]['customer'])->toBe('Sara Khan');
});

// --- who sees what -------------------------------------------------------------------------------

test('guests get a 401 and an unknown card a 404', function () {
    $this->getJson('/api/dashboard/sales')->assertUnauthorized();

    trpActAsSuperadmin();
    $this->getJson('/api/dashboard/nonsense')->assertNotFound();
});

test('a figure is sent only when the user may open the page it comes from', function (string $widget, array $paths, array $expected) {
    $scope = trpScope('A');
    Sanctum::actingAs(dshUser($scope, $paths));

    $data = dshGet($widget)->assertSuccessful()->json('data');

    expect(array_keys($data))->toBe($expected);
})->with([
    'sales only' => ['sales', ['/sell'], ['sales']],
    'purchases only' => ['sales', ['/purchase'], ['purchases']],
    'both' => ['sales', ['/sell', '/purchase'], ['sales', 'purchases']],
    'neither' => ['sales', ['/customer'], []],
    'receivables page only' => ['receivables', ['/report/customer-outstanding'], ['receivables']],
    'payables page only' => ['receivables', ['/report/supplier-outstanding'], ['payables']],
    'credit watch needs the customer page' => ['receivables', ['/customer'], ['credit_watch']],
    'no ledger permission' => ['receivables', ['/sell'], []],
    'low stock only' => ['inventory', ['/lowstock'], ['low_stock']],
    'stock value only' => ['inventory', ['/report/stock'], ['stock_value']],
    'one approval' => ['approvals', ['/purchase/approval'], ['purchase']],
    'two approvals' => ['approvals', ['/journalentry/approval', '/deposit/approval'], ['journal', 'deposit']],
    'the sell approval is not the sell list' => ['approvals', ['/sell'], []],
    'net profit only' => ['financial', ['/report/profit-loss'], ['net_profit']],
    'cash and bank only' => ['financial', ['/chart-of-account'], ['cash_bank']],
    'stats' => ['stats', ['/customer', '/product'], ['customers', 'products']],
    'recent sales' => ['recent', ['/sell'], ['recent_sales']],
    'recent sales need the sell page' => ['recent', ['/purchase'], []],
]);

test('a user with no permission at all gets an empty answer from every card', function () {
    $scope = trpScope('A');
    Sanctum::actingAs(dshUser($scope, []));

    foreach (array_keys(DashboardController::PERMISSIONS) as $widget) {
        dshGet($widget)->assertSuccessful()->assertJsonPath('data', [])->assertJsonPath('needs_company', []);
    }
});

test('the superadmin gets every part', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();

    foreach (DashboardController::PERMISSIONS as $widget => $parts) {
        $data = dshGet($widget, ['company_id' => $scope['company_id']])->assertSuccessful()->json('data');

        expect(array_keys($data))->toEqualCanonicalizing(array_keys($widget === 'recent' ? ['recent_sales' => 1] : $parts));
    }
});

// --- company and branch scope --------------------------------------------------------------------

test('a company user sees their own company whatever company they ask for', function () {
    $mine = trpScope('A');
    $theirs = trpScope('B');
    dshDoc($mine, 'sell', 100, '2026-09-15');
    dshDoc($theirs, 'sell', 9000, '2026-09-15');
    dshDoc($theirs, 'purchaseorder', 8000, '2026-09-15', ['status' => 'pending']);
    Sanctum::actingAs(dshUser($mine, ['/sell', '/purchase/approval', '/report/customer-outstanding'], ['branch_id' => null]));
    Role::query()->where('company_id', $mine['company_id'])->update(['name' => 'companyadmin']);

    foreach ([[], ['company_id' => $theirs['company_id']]] as $query) {
        expect(dshData('sales', $query)['sales']['today'])->toEqual(100)
            ->and(dshData('approvals', $query)['purchase'])->toBe(0);
    }

    dshDoc($theirs, 'sell', 1, '2026-09-01', ['contact_id' => prpContact($theirs, 'customer', 'Foreign Debtor')]);
    dshDoc($mine, 'sell', 100, '2026-09-01');

    // asking for the other company gets their own company's ledger: 100 (their sale), not 9001
    expect(dshData('receivables', ['company_id' => $theirs['company_id']])['receivables']['total_due'])->toEqual(200);
});

test('a branch user sees only their branch and a company admin may pick one', function () {
    $scope = trpScope('A');
    $second = trpBranch($scope['company_id'], 'Second Branch');
    dshDoc($scope, 'sell', 100, '2026-09-15');
    dshDoc($scope, 'sell', 40, '2026-09-15', ['branch_id' => $second]);

    Sanctum::actingAs(dshUser($scope, ['/sell'], ['branch_id' => $second]));
    expect(dshData('sales')['sales']['today'])->toEqual(40)
        ->and(dshData('sales', ['branch_id' => $scope['branch_id']])['sales']['today'])->toEqual(40);

    Role::query()->where('company_id', $scope['company_id'])->update(['name' => 'companyadmin']);
    Sanctum::actingAs(User::query()->where('branch_id', $second)->firstOrFail());
    Cache::flush();

    expect(dshData('sales')['sales']['today'])->toEqual(140)
        ->and(dshData('sales', ['branch_id' => $scope['branch_id']])['sales']['today'])->toEqual(100);
});

test('a superadmin who has picked no company sees every company on the light cards and is asked to pick one for the rest', function () {
    $a = trpScope('A');
    $b = trpScope('B');
    dshDoc($a, 'sell', 100, '2026-09-15');
    dshDoc($b, 'sell', 250, '2026-09-15');
    trpActAsSuperadmin();

    expect(dshData('sales')['sales']['today'])->toEqual(350);

    $inventory = dshGet('inventory')->assertSuccessful();
    expect(array_keys($inventory->json('data')))->toBe(['low_stock'])
        ->and($inventory->json('needs_company'))->toBe(['stock_value']);

    foreach (['receivables' => ['receivables', 'payables', 'credit_watch'], 'financial' => ['net_profit', 'cash_bank']] as $widget => $parts) {
        dshGet($widget)->assertSuccessful()->assertJsonPath('data', [])->assertJsonPath('needs_company', $parts);
    }

    expect(dshData('sales', ['company_id' => $b['company_id']])['sales']['today'])->toEqual(250);
});

// --- cost: the heavy cards are cached ------------------------------------------------------------

test('the heavy figures are kept for five minutes per company and recalculated on request', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();
    $customer = prpContact($scope, 'customer', 'Debtor');
    dshDoc($scope, 'sell', 100, '2026-09-01', ['contact_id' => $customer]);

    $first = dshGet('receivables', ['company_id' => $scope['company_id']])->assertSuccessful();
    expect($first->json('data.receivables.total_due'))->toEqual(100)
        ->and($first->json('cached_at'))->not->toBeNull();

    dshDoc($scope, 'sell', 50, '2026-09-02', ['contact_id' => $customer]);
    Carbon::setTestNow('2026-09-15 12:04:00');

    expect(dshGet('receivables', ['company_id' => $scope['company_id']])->json('data.receivables.total_due'))->toEqual(100);

    $refreshed = dshGet('receivables', ['company_id' => $scope['company_id'], 'refresh' => 1])->assertSuccessful();
    expect($refreshed->json('data.receivables.total_due'))->toEqual(150)
        ->and($refreshed->json('cached_at'))->toBe('2026-09-15T12:04:00+00:00');

    dshDoc($scope, 'sell', 25, '2026-09-03', ['contact_id' => $customer]);
    Carbon::setTestNow('2026-09-15 12:09:30');

    expect(dshGet('receivables', ['company_id' => $scope['company_id']])->json('data.receivables.total_due'))->toEqual(175);
});

test('one company\'s cached figures are never served to another', function () {
    $a = trpScope('A');
    $b = trpScope('B');
    dshDoc($a, 'sell', 100, '2026-09-01', ['contact_id' => prpContact($a, 'customer', 'A Debtor')]);
    dshDoc($b, 'sell', 900, '2026-09-01', ['contact_id' => prpContact($b, 'customer', 'B Debtor')]);
    trpActAsSuperadmin();

    expect(dshData('receivables', ['company_id' => $a['company_id']])['receivables']['total_due'])->toEqual(100)
        ->and(dshData('receivables', ['company_id' => $b['company_id']])['receivables']['total_due'])->toEqual(900)
        ->and(dshData('receivables', ['company_id' => $a['company_id']])['receivables']['total_due'])->toEqual(100);

    $staff = dshUser($b, ['/report/customer-outstanding', '/report/supplier-outstanding', '/customer']);
    Sanctum::actingAs($staff);

    expect(dshData('receivables')['receivables']['total_due'])->toEqual(900);
});

test('the cache holds the raw figures, so a user with less permission is not given the rest', function () {
    $scope = trpScope('A');
    dshDoc($scope, 'sell', 100, '2026-09-01', ['contact_id' => prpContact($scope, 'customer', 'Debtor')]);
    trpActAsSuperadmin();
    expect(array_keys(dshData('receivables', ['company_id' => $scope['company_id']])))->toBe(['receivables', 'payables', 'credit_watch']);

    Sanctum::actingAs(dshUser($scope, ['/report/supplier-outstanding']));

    expect(array_keys(dshData('receivables')))->toBe(['payables']);
});

// --- cost: no query per row ----------------------------------------------------------------------

/**
 * How many queries a request runs.
 *
 * @param  array<string, mixed>  $query
 */
function dshQueries(string $widget, array $query = []): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    dshGet($widget, $query)->assertSuccessful();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('the cards cost the same number of queries however many records there are', function () {
    $scope = dshBooks('Q');
    trpActAsSuperadmin();
    $query = ['company_id' => $scope['company_id']];
    $seed = function (int $from, int $to) use ($scope): void {
        foreach (range($from, $to) as $n) {
            $customer = prpContact($scope, $n % 2 ? 'customer' : 'supplier', 'Party '.$n);
            dshDoc($scope, 'sell', 100 + $n, '2026-09-10', ['contact_id' => $customer]);
            dshDoc($scope, 'purchaseorder', 50 + $n, '2026-09-11', ['contact_id' => $customer, 'status' => $n % 3 ? 'pending' : 'received']);
            dshDoc($scope, 'sell', 10, '2026-09-12', ['contact_id' => $customer, 'status' => 'final']);
            prdPurchase($scope, prdProduct($scope, 'Product '.$n, ['alert_qty' => 1000]), $n, 5, '2026-09-01');
            ldgVoucher($scope, 'JV-'.(1000 + $n), '2026-09-10', [['211-00001', 5, 0], ['511-00001', 0, 5]], ['status' => $n % 2 ? 'pending' : 'approved']);
        }
    };

    $widgets = ['sales', 'approvals', 'stats', 'recent', 'inventory', 'financial'];
    foreach ($widgets as $widget) {
        dshGet($widget, $query)->assertSuccessful();
    }

    $seed(1, 5);
    $small = [];
    foreach ($widgets as $widget) {
        Cache::flush();
        $small[$widget] = dshQueries($widget, $query);
    }

    $seed(6, 60);
    $large = [];
    foreach ($widgets as $widget) {
        Cache::flush();
        $large[$widget] = dshQueries($widget, $query);
    }

    expect($large)->toBe($small);
});

test('the ledger card runs its queries per contact, which is why it is cached, and a cached answer costs none', function () {
    $scope = trpScope('A');
    trpActAsSuperadmin();
    $query = ['company_id' => $scope['company_id']];

    foreach (range(1, 40) as $n) {
        dshDoc($scope, 'sell', 100 + $n, '2026-09-10', ['contact_id' => prpContact($scope, 'customer', 'Debtor '.$n, ['credit_limit' => 200])]);
    }

    $cold = dshQueries('receivables', $query);
    $warm = dshQueries('receivables', $query);

    // the credit watch is one query for all the limits, not one per customer
    expect($cold)->toBeLessThan(40 * 12)
        ->and($warm)->toBe(0);
});

test('a big company still answers all the cards', function () {
    $scope = dshBooks('H');
    trpActAsSuperadmin();

    foreach (range(1, 150) as $n) {
        $customer = prpContact($scope, 'customer', 'Customer '.$n, ['credit_limit' => 300]);
        dshDoc($scope, 'sell', 100 + ($n % 7) * 50, '2026-09-'.str_pad((string) (($n % 14) + 1), 2, '0', STR_PAD_LEFT), ['contact_id' => $customer]);
        prdPurchase($scope, prdProduct($scope, 'Big Product '.$n, ['alert_qty' => 500]), $n % 9 + 1, 20, '2026-09-01');
    }

    $started = microtime(true);

    foreach (array_keys(DashboardController::PERMISSIONS) as $widget) {
        dshGet($widget, ['company_id' => $scope['company_id']])->assertSuccessful();
    }

    expect(microtime(true) - $started)->toBeLessThan(30.0)
        ->and(dshData('receivables', ['company_id' => $scope['company_id']])['receivables']['contacts'])->toBe(150)
        ->and(dshData('inventory', ['company_id' => $scope['company_id']])['low_stock']['count'])->toBe(150);
});
