<?php

use App\Mail\ScheduledReportMail;
use App\Models\CashCollection;
use App\Models\CommissionAgent;
use App\Models\Role;
use App\Models\User;
use App\Services\Reports\InsightReportCatalog;
use App\Services\Reports\ReportScheduler;
use App\Services\SalesForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 4: comparative statements, indirect cash flow, sales and profit by dimension, commission / target, forecast, stock take,
 * advances, withholding, exemptions, tax liability, FBR audit trail, saved filters, the report builder and scheduled reports.
 */
function w4Books(): array
{
    $scope = trpScope('W4');
    prpFinancialYear($scope['company_id'], '2026-07-01', '2027-07-01');

    $cash = ldgAccount($scope, '212-00001', 'Cash in Hand');
    $capital = ldgAccount($scope, '100-00001', 'Owner Capital', 't', 'cr');
    ldgAccount($scope, '511-00001', 'Local Sales', 't', 'dr');
    ldgAccount($scope, '441-00001', 'General Expense');
    ldgAccount($scope, '441-00002', 'Old Expense');
    ldgAccount($scope, '221-00001', 'Office Equipment');
    ldgAccount($scope, '321-00001', 'Bank Loan', 't', 'cr');
    prpOpeningBalance($scope, $cash, 1000, 'dr');
    prpOpeningBalance($scope, $capital, 1000, 'cr');
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cash);

    ldgVoucher($scope, 'JV-1', '2026-08-10', [['212-00001', 500, 0], ['511-00001', 0, 500]]);
    ldgVoucher($scope, 'JV-2', '2026-08-12', [['441-00002', 100, 0], ['212-00001', 0, 100]]);
    ldgVoucher($scope, 'JV-3', '2026-09-05', [['212-00001', 800, 0], ['511-00001', 0, 800]]);
    ldgVoucher($scope, 'JV-4', '2026-09-06', [['441-00001', 200, 0], ['212-00001', 0, 200]]);
    ldgVoucher($scope, 'JV-5', '2026-09-10', [['221-00001', 300, 0], ['212-00001', 0, 300]]);
    ldgVoucher($scope, 'JV-6', '2026-09-12', [['212-00001', 1000, 0], ['321-00001', 0, 1000]]);

    return $scope;
}

function w4Row(array $rows, string $code, string $name = ''): ?array
{
    return collect($rows)->first(fn (array $row): bool => $row['code'] === $code && ($name === '' || $row['name'] === $name));
}

function w4Staff(array $scope, array $paths, bool $branchBound = false): User
{
    return fcoStaff($scope, $paths, $branchBound);
}

// ---------------------------------------------------------------------------------------------------------------------
// comparative statements and indirect cash flow

test('the comparative profit and loss lines up two periods and matches the single-period statement', function () {
    $scope = w4Books();
    trpActAsSuperadmin();
    $query = ['company_id' => $scope['company_id'], 'start_date' => '2026-09-01', 'end_date' => '2026-09-30'];

    $both = $this->getJson('/api/reports/profit-loss-comparative?'.http_build_query($query + ['compare_start' => '2026-08-01', 'compare_end' => '2026-08-31']))->assertOk();
    $summary = $both->json('summary');

    expect($summary['revenue'])->toEqual(800)->and($summary['revenue_previous'])->toEqual(500)->and($summary['net_profit'])->toEqual(600)
        ->and($summary['net_profit_previous'])->toEqual(400)->and($summary['net_profit_change'])->toEqual(200);

    $rows = $this->getJson('/api/reports/profit-loss-comparative?'.http_build_query($query + ['compare_start' => '2026-08-01', 'compare_end' => '2026-08-31', 'show_record' => 100]))->json('data.data');
    $sales = w4Row($rows, '511-00001');
    $oldExpense = w4Row($rows, '441-00002');

    expect($sales)->toMatchArray(['current' => 800.0, 'previous' => 500.0, 'change' => 300.0, 'change_percent' => 60.0])
        ->and($oldExpense)->toMatchArray(['current' => 0.0, 'previous' => 100.0]);

    // the default comparison is the period of the same length right before
    $default = $this->getJson('/api/reports/profit-loss-comparative?'.http_build_query($query))->json('summary');
    expect($default['revenue_previous'])->toEqual(500)->and($default['previous_to'])->toBe('2026-08-31');

    // the current column is exactly the single-period report
    $single = $this->getJson('/api/reports/profit-loss?'.http_build_query($query))->json('summary');
    expect($summary['net_profit'])->toEqual($single['net_profit'])->and($summary['gross_profit'])->toEqual($single['gross_profit']);
});

test('the comparative balance sheet shows two days and stays balanced; a company with no stock gets no stock lines', function () {
    $scope = w4Books();
    trpActAsSuperadmin();

    $response = $this->getJson('/api/reports/balance-sheet-comparative?'.http_build_query(['company_id' => $scope['company_id'], 'end_date' => '2026-09-30', 'compare_end' => '2026-08-31', 'show_record' => 100]))->assertOk();
    $summary = $response->json('summary');
    $rows = $response->json('data.data');

    expect($summary['total_assets'])->toEqual(3000)->and($summary['total_assets_previous'])->toEqual(1400)
        ->and($summary['difference'])->toEqual(0)->and($summary['difference_previous'])->toEqual(0)
        ->and($summary['inventory_method'])->toBe('average');

    $cash = w4Row($rows, '212-00001');
    expect($cash)->toMatchArray(['current' => 2700.0, 'previous' => 1400.0, 'change' => 1300.0]);
    // Changed when the sheet began to carry stock as an asset: these books hold no stock at all, so there is no stock line (it used to
    // be a memo line of 0.00 on every sheet) and every figure above is what it always was.
    expect(collect($rows)->contains(fn (array $row): bool => $row['line'] === 'stock'))->toBeFalse()
        ->and($summary['includes_stock'])->toBeFalse()->and($summary['inventory'])->toEqual(0);

    $single = $this->getJson('/api/reports/balance-sheet?'.http_build_query(['company_id' => $scope['company_id'], 'end_date' => '2026-09-30']))->json('summary');
    expect($summary['total_assets'])->toEqual($single['total_assets']);
});

test('the indirect cash flow reconciles to the direct method', function () {
    $scope = w4Books();
    trpActAsSuperadmin();
    $query = http_build_query(['company_id' => $scope['company_id'], 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'show_record' => 100]);

    $indirect = $this->getJson('/api/reports/cash-flow-indirect?'.$query)->assertOk();
    $summary = $indirect->json('summary');
    $direct = $this->getJson('/api/reports/cash-flow?'.$query)->json('summary');

    expect($summary['profit'])->toEqual(600)->and($summary['operating'])->toEqual(600)->and($summary['investing'])->toEqual(-300)->and($summary['financing'])->toEqual(1000)
        ->and($summary['net_change'])->toEqual(1300)->and($summary['difference_to_direct'])->toEqual(0)
        ->and($summary['net_change'])->toEqual($direct['net_change'])->and($summary['opening_cash'])->toEqual($direct['opening_cash'])
        ->and($summary['closing_cash'])->toEqual($direct['closing_cash']);
});

// ---------------------------------------------------------------------------------------------------------------------
// sales and profit by dimension

function w4Sales(): array
{
    $scope = trpScope('W4S');
    $role = Role::query()->create(['name' => 'w4sales', 'company_id' => $scope['company_id'], 'is_active' => true]);
    $alice = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'first_name' => 'Alice', 'last_name' => 'Seller']);
    $bob = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'first_name' => 'Bob', 'last_name' => 'Seller']);
    $warehouse = DB::table('warehouses')->insertGetId(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'name' => 'Main Store', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $a = trpDoc($scope, 'sell', ['created_by' => $alice->id, 'warehouse_id' => $warehouse, 'final_amount' => 1000, 'total_before_tax' => 900, 'tax_amount' => 100, 'discount_amount' => 0, 'transaction_date' => '2026-09-10 10:00:00']);
    $b = trpDoc($scope, 'sell', ['created_by' => $bob->id, 'warehouse_id' => null, 'final_amount' => 500, 'total_before_tax' => 500, 'tax_amount' => 0, 'discount_amount' => 0, 'transaction_date' => '2026-09-12 10:00:00']);
    $r = trpDoc($scope, 'sellreturn', ['created_by' => $alice->id, 'parent_id' => $a, 'final_amount' => 100, 'total_before_tax' => 100, 'tax_amount' => 0, 'discount_amount' => 0, 'transaction_date' => '2026-09-15 10:00:00']);
    trpDoc($scope, 'sell', ['created_by' => $alice->id, 'status' => 'draft', 'final_amount' => 9999, 'transaction_date' => '2026-09-16 10:00:00']);
    trpPayment($scope, $a, 600, '2026-09-10', 'cash');
    trpPayment($scope, $a, 400, '2026-09-10', 'card');
    trpPayment($scope, $b, 500, '2026-09-12', 'cash');
    trpPayment($scope, $r, 100, '2026-09-15', 'cash');

    return $scope + ['alice' => $alice->id, 'bob' => $bob->id, 'warehouse' => $warehouse];
}

test('sales by salesperson, warehouse and payment method net the returns and leave drafts out', function () {
    $scope = w4Sales();
    $staff = w4Staff($scope, ['/report/sales-by-salesperson', '/report/sales-by-warehouse', '/report/sales-by-payment-method']);
    Sanctum::actingAs($staff);
    $range = 'start_date=2026-09-01&end_date=2026-09-30';

    $byUser = collect($this->getJson("/api/reports/sales-by-salesperson?{$range}")->assertOk()->json('data.data'))->keyBy('name');
    expect($byUser['Alice Seller'])->toMatchArray(['sales_count' => 1, 'gross' => 1000.0, 'returned' => 100.0, 'net' => 900.0, 'tax' => 100.0])
        ->and($byUser['Bob Seller']['net'])->toEqual(500.0);

    $byWarehouse = collect($this->getJson("/api/reports/sales-by-warehouse?{$range}")->json('data.data'))->keyBy('name');
    expect($byWarehouse['Main Store']['net'])->toEqual(900.0)->and($byWarehouse['Unassigned']['net'])->toEqual(500.0);

    $summary = $this->getJson("/api/reports/sales-by-salesperson?{$range}")->json('summary');
    expect($summary['net'])->toEqual(1400)->and($summary['sales_count'])->toBe(2);

    $methods = collect($this->getJson("/api/reports/sales-by-payment-method?{$range}")->json('data.data'))->keyBy('name');
    expect($methods['Cash'])->toMatchArray(['gross' => 1100.0, 'returned' => 100.0, 'net' => 1000.0])->and($methods['Card']['net'])->toEqual(400.0);
});

test('profit by customer, branch, salesperson and warehouse agrees with item profit and loss', function () {
    $scope = trpScope('W4P');
    $second = trpContact($scope, 'Beta Traders', 'customer', 'CU-BETA');
    $role = Role::query()->create(['name' => 'w4profit', 'company_id' => $scope['company_id'], 'is_active' => true]);
    $alice = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'first_name' => 'Alice', 'last_name' => 'P']);
    $product = prdProduct($scope, 'Widget');
    prdPurchase($scope, $product, 100, 10, '2026-08-01');
    prdSale($scope, $product, 10, 25, '2026-09-10', ['created_by' => $alice->id]);
    prdSale($scope, $product, 4, 30, '2026-09-11', ['contact_id' => $second, 'created_by' => $alice->id]);
    $staff = w4Staff($scope, ['/report/profit-by-customer', '/report/profit-by-branch', '/report/profit-by-salesperson', '/report/profit-by-warehouse', '/report/item-profit-loss']);
    Sanctum::actingAs($staff);
    $range = 'start_date=2026-09-01&end_date=2026-09-30';

    $customers = collect($this->getJson("/api/reports/profit-by-customer?{$range}")->assertOk()->json('data.data'))->keyBy('name');
    expect($customers['Acme Retail W4P'])->toMatchArray(['sales' => 250.0, 'cost' => 100.0, 'profit' => 150.0])
        ->and($customers['Beta Traders'])->toMatchArray(['sales' => 120.0, 'cost' => 40.0, 'profit' => 80.0]);

    $item = $this->getJson("/api/reports/item-profit-loss?{$range}")->json('summary');

    foreach (['customer', 'branch', 'salesperson', 'warehouse'] as $dimension) {
        $summary = $this->getJson("/api/reports/profit-by-{$dimension}?{$range}")->assertOk()->json('summary');
        expect($summary['profit'])->toEqual($item['profit'])->and($summary['sales'])->toEqual($item['sales']);
    }

    $branch = $this->getJson("/api/reports/profit-by-branch?{$range}")->json('data.data');
    expect($branch)->toHaveCount(1)->and($branch[0]['name'])->toBe('Report Branch W4P');
    $warehouse = $this->getJson("/api/reports/profit-by-warehouse?{$range}")->json('data.data');
    expect($warehouse[0]['name'])->toBe('Unassigned');
});

test('the commission and target reports are the KPI statement of the month', function () {
    $scope = trpScope('W4K');
    $agent = CommissionAgent::query()->create(['company_id' => $scope['company_id'], 'name' => 'Agent Zed', 'commission_percent' => 2, 'is_active' => true]);
    $other = CommissionAgent::query()->create(['company_id' => $scope['company_id'], 'name' => 'Agent Idle', 'commission_percent' => 5, 'is_active' => true]);
    trpDoc($scope, 'sell', ['commission_agent_id' => $agent->id, 'final_amount' => 5000, 'transaction_date' => '2026-10-05 10:00:00']);
    trpDoc($scope, 'sell', ['commission_agent_id' => $agent->id, 'final_amount' => 3000, 'transaction_date' => '2026-10-15 10:00:00']);
    DB::table('sales_targets')->insert(['company_id' => $scope['company_id'], 'subject_type' => 'agent', 'subject_id' => $agent->id, 'period' => '2026-10', 'target_amount' => 10000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('commission_tiers')->insert(['company_id' => $scope['company_id'], 'commission_agent_id' => null, 'threshold_amount' => 7000, 'rate_percent' => 3, 'created_at' => now(), 'updated_at' => now()]);

    $staff = w4Staff($scope, ['/report/commission-report', '/report/target-achievement', '/saleskpi']);
    Sanctum::actingAs($staff);

    $commission = $this->getJson('/api/reports/commission-report?period=2026-10&subject=agent')->assertOk();
    $row = collect($commission->json('data.data'))->firstWhere('name', 'Agent Zed');
    expect($row)->toMatchArray(['net' => 8000.0, 'rate_percent' => 3.0, 'commission' => 240.0])
        ->and($other->id)->toBeInt();

    $target = $this->getJson('/api/reports/target-achievement?period=2026-10&subject=agent')->assertOk();
    $row = $target->json('data.data.0');
    expect($row)->toMatchArray(['name' => 'Agent Zed', 'target' => 10000.0, 'achievement_percent' => 80.0, 'shortfall' => 2000.0])
        ->and($target->json('summary.targets_met'))->toBe(0);

    // the same figures as the KPI page
    $kpi = $this->getJson('/api/sales-kpi/statement?period=2026-10&subject=agent')->json('data');
    expect(collect($kpi)->firstWhere('name', 'Agent Zed')['commission'])->toEqual(240);
});

test('the sales forecast report is the dashboard forecast engine', function () {
    $scope = trpScope('W4F');
    $today = Carbon::today();

    foreach ([4, 3, 2, 1] as $back) {
        $date = $today->copy()->startOfMonth()->subMonthsNoOverflow($back)->addDays(5)->toDateTimeString();
        trpDoc($scope, 'sell', ['final_amount' => 1000 + $back * 100, 'total_before_tax' => 1000, 'tax_amount' => 0, 'discount_amount' => 0, 'transaction_date' => $date]);
    }

    Sanctum::actingAs(w4Staff($scope, ['/report/sales-forecast']));
    $response = $this->getJson('/api/reports/sales-forecast')->assertOk();
    $engine = app(SalesForecast::class)->forCompany($scope['company_id'], null, $today);

    expect($response->json('summary.estimate'))->toEqual($engine['estimate'])->and($engine['estimate'])->not->toBeNull()
        ->and(collect($response->json('data.data'))->where('kind', 'Actual')->count())->toBe(4);
});

// ---------------------------------------------------------------------------------------------------------------------
// stock take, advance, withholding, exemption, tax liability, FBR

test('stock take variance values the difference at the unit cost of the count date', function () {
    $scope = trpScope('W4T');
    $product = prdProduct($scope, 'Counted Item');
    prdPurchase($scope, $product, 50, 8, '2026-08-01');
    $take = DB::table('stock_takes')->insertGetId(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'reference' => 'ST-1', 'count_date' => '2026-09-20', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('stock_take_lines')->insert([
        ['stock_take_id' => $take, 'product_id' => $product['product_id'], 'variation_id' => $product['variation_id'], 'unit_id' => $product['unit_id'], 'system_qty' => 50, 'counted_qty' => 47, 'created_at' => now(), 'updated_at' => now()],
    ]);

    Sanctum::actingAs(w4Staff($scope, ['/report/stock-take']));
    $response = $this->getJson('/api/reports/stock-take?start_date=2026-09-01&end_date=2026-09-30')->assertOk();

    expect($response->json('data.data.0'))->toMatchArray(['variance_qty' => -3.0, 'unit_cost' => 8.0, 'variance_value' => -24.0])
        ->and($response->json('summary'))->toMatchArray(['with_variance' => 1, 'shortage_value' => 24.0, 'net_value' => -24.0]);
});

test('the advance report lists customer advances with what is left', function () {
    $scope = trpScope('W4A');
    CashCollection::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['customer_id'], 'reference' => 'CC-1', 'collected_on' => '2026-09-10',
        'amount' => 1000, 'status' => 'completed', 'advance_amount' => 400,
    ]);
    DB::table('cash_collections')->where('reference', 'CC-1')->update(['company_id' => $scope['company_id']]);

    Sanctum::actingAs(w4Staff($scope, ['/report/advance']));
    $response = $this->getJson('/api/reports/advance?start_date=2026-09-01&end_date=2026-09-30&contact_type=customer')->assertOk();

    expect($response->json('summary'))->toMatchArray(['customer_advances' => 400.0, 'supplier_advances' => 0.0, 'count' => 1])
        ->and($response->json('data.data.0.contact_name'))->toBe('Acme Retail W4A');
});

test('withholding, exemptions and tax liability read the documents', function () {
    $scope = trpScope('W4X');
    $withholding = DB::table('taxes')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'WHT 4%', 'percentage' => 4, 'type' => 'withholding', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $sale = trpDoc($scope, 'sell', ['final_amount' => 1000, 'total_before_tax' => 900, 'tax_amount' => 100, 'withholding_tax_id' => $withholding, 'withholding_amount' => 40, 'transaction_date' => '2026-09-10 10:00:00']);
    trpDoc($scope, 'purchaseorder', ['final_amount' => 500, 'total_before_tax' => 450, 'tax_amount' => 50, 'withholding_tax_id' => $withholding, 'withholding_amount' => 20, 'status' => 'received', 'transaction_date' => '2026-09-11 10:00:00']);

    $exemption = DB::table('tax_exemptions')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'Export certificate', 'scope' => 'contact', 'certificate_no' => 'CERT-9', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $salesTax = DB::table('taxes')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'GST 18%', 'percentage' => 18, 'type' => 'tax', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $product = prdProduct($scope, 'Exempt Item');
    prdSellLine($sale, $product, 10, 20, ['tax_id' => $salesTax, 'tax_exemption_id' => $exemption, 'tax_amount' => 0]);
    $taxAccount = ldgAccount($scope, '301-00001', 'Sales Tax Payable', 't', 'cr');
    $bankAccount = ldgAccount($scope, '212-00002', 'Bank');
    DB::table('tax_payments')->insert(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'tax_type' => 'GST', 'period' => '2026-09', 'challan_no' => 'CH-1', 'tax_account_id' => $taxAccount, 'bank_account_id' => $bankAccount, 'amount' => 30, 'paid_on' => '2026-09-20', 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs(w4Staff($scope, ['/report/withholding-tax', '/report/tax-exemption', '/report/tax-liability']));
    $range = 'start_date=2026-09-01&end_date=2026-09-30';

    $wht = $this->getJson("/api/reports/withholding-tax?{$range}")->assertOk()->json('summary');
    expect($wht)->toMatchArray(['receivable' => 40.0, 'payable' => 20.0, 'net_payable' => -20.0, 'count' => 2]);
    expect($this->getJson("/api/reports/withholding-tax?{$range}&tax_side=payable")->json('summary.count'))->toBe(1);

    $exempt = $this->getJson("/api/reports/tax-exemption?{$range}")->assertOk()->json('summary');
    expect($exempt)->toMatchArray(['lines' => 1, 'amount' => 200.0, 'tax_forgone' => 36.0]);

    $liability = $this->getJson("/api/reports/tax-liability?{$range}")->assertOk()->json('summary');
    expect($liability)->toMatchArray(['output_tax' => 100.0, 'input_tax' => 50.0, 'net_tax' => 50.0, 'paid' => 30.0, 'balance' => 20.0, 'output_account_mapped' => 0]);
});

test('the FBR audit trail lists submissions and says the gateway is a stub', function () {
    $scope = trpScope('W4B');
    $sale = trpDoc($scope, 'sell', ['transaction_date' => '2026-09-10 10:00:00']);
    DB::table('fbr_submissions')->insert(['company_id' => $scope['company_id'], 'transaction_id' => $sale, 'status' => 'stub', 'fbr_invoice_number' => 'FBR-STUB-1-X', 'attempts' => 1, 'created_at' => '2026-09-10 11:00:00', 'updated_at' => now()]);

    Sanctum::actingAs(w4Staff($scope, ['/report/fbr-audit-trail']));
    $response = $this->getJson('/api/reports/fbr-audit-trail?start_date=2026-09-01&end_date=2026-09-30')->assertOk();

    expect($response->json('summary.stub'))->toBe(1)->and($response->json('summary.submitted'))->toBe(0)
        ->and($response->json('summary.note'))->toContain('stub')->and($response->json('data.data.0.is_real'))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------------------------------
// permission, tenant isolation, read-only guarantee

test('every wave 4 report needs its permission and shows only the signed-in company', function () {
    $mine = w4Sales();
    $theirs = trpScope('W4Z');
    trpDoc($theirs, 'sell', ['final_amount' => 7777, 'transaction_date' => '2026-09-10 10:00:00']);
    $product = prdProduct($theirs, 'Foreign Item');
    prdPurchase($theirs, $product, 10, 5, '2026-08-01');
    prdSale($theirs, $product, 2, 50, '2026-09-10');

    $keys = array_keys(InsightReportCatalog::REPORTS);
    $plain = w4Staff($mine, []);
    Sanctum::actingAs($plain);

    foreach ($keys as $key) {
        $this->getJson("/api/reports/{$key}?start_date=2026-09-01&end_date=2026-09-30")->assertForbidden();
    }

    $allowed = w4Staff($mine, array_map(fn (string $key): string => "/report/{$key}", $keys));
    Sanctum::actingAs($allowed);
    $range = 'start_date=2026-09-01&end_date=2026-09-30&period=2026-09';

    foreach ($keys as $key) {
        // a company id sent by the client is ignored for a company user
        $response = $this->getJson("/api/reports/{$key}?{$range}&company_id={$theirs['company_id']}")->assertOk();
        expect(json_encode($response->json()))->not->toContain('7777')->and(json_encode($response->json()))->not->toContain('Foreign Item');
    }

    $web = $this->get('/report/sales-by-salesperson');
    expect($web->status())->toBeIn([200, 302]);
});

test('running every wave 4 report changes nothing in the books or stock', function () {
    $scope = w4Books();
    $sales = w4Sales();
    $keys = array_keys(InsightReportCatalog::REPORTS);
    trpActAsSuperadmin();

    $snapshot = fn (): array => collect(['t_accounts', 't_account_details', 'transactions', 'payments', 'sell_lines', 'purchase_lines', 'chart_of_accounts', 'stock_takes', 'cash_collections'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count().':'.DB::table($table)->max('updated_at')])->all();
    $before = $snapshot();

    foreach ($keys as $key) {
        $this->getJson("/api/reports/{$key}?company_id={$scope['company_id']}&start_date=2026-09-01&end_date=2026-09-30")->assertOk();
        $this->getJson("/api/reports/{$key}?company_id={$sales['company_id']}&start_date=2026-09-01&end_date=2026-09-30")->assertOk();
    }

    expect($snapshot())->toEqual($before);
});

// ---------------------------------------------------------------------------------------------------------------------
// saved filters

test('saved filters are the user own, can be favourites and ignore fields that are not filters', function () {
    $scope = trpScope('W4V');
    $alice = w4Staff($scope, ['/report/sales-by-salesperson']);
    $bob = w4Staff($scope, ['/report/sales-by-salesperson']);

    Sanctum::actingAs($alice);
    $id = $this->postJson('/api/report-filters', [
        'report_key' => 'sales-by-salesperson', 'name' => 'September', 'is_favourite' => true,
        'filters' => ['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'company_id' => 999, 'evil' => 'x'],
    ])->assertCreated()->json('data.id');

    $listed = $this->getJson('/api/report-filters?report_key=sales-by-salesperson')->assertOk()->json('data');
    expect($listed)->toHaveCount(1)->and($listed[0]['filters'])->toBe(['start_date' => '2026-09-01', 'end_date' => '2026-09-30'])->and($listed[0]['is_favourite'])->toBeTrue();

    // saving under the same name replaces, not duplicates
    $this->postJson('/api/report-filters', ['report_key' => 'sales-by-salesperson', 'name' => 'September', 'filters' => ['start_date' => '2026-09-02']])->assertCreated();
    expect(DB::table('report_filters')->count())->toBe(1);

    $this->postJson('/api/report-filters', ['report_key' => 'not-a-report', 'name' => 'Nope', 'filters' => []])->assertUnprocessable();

    Sanctum::actingAs($bob);
    $this->getJson('/api/report-filters')->assertOk()->assertJsonCount(0, 'data');
    $this->putJson("/api/report-filters/{$id}/favourite", ['is_favourite' => false])->assertNotFound();
    $this->deleteJson("/api/report-filters/{$id}")->assertNotFound();

    Sanctum::actingAs($alice);
    $this->putJson("/api/report-filters/{$id}/favourite", ['is_favourite' => false])->assertOk();
    $this->deleteJson("/api/report-filters/{$id}")->assertOk();
    expect(DB::table('report_filters')->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------------------------------
// report builder

test('the report builder runs whitelisted datasets, groups, and refuses anything else', function () {
    $scope = w4Sales();
    $other = trpScope('W4O');
    trpDoc($other, 'sell', ['final_amount' => 4242, 'transaction_date' => '2026-09-10 10:00:00']);
    $staff = w4Staff($scope, ['/reportbuilder', '/reportbuilder/manage']);
    Sanctum::actingAs($staff);

    $plain = $this->postJson('/api/report-builder/preview', ['dataset' => 'sales', 'definition' => [
        'columns' => ['invoice_no', 'contact', 'final_amount'], 'from' => '2026-09-01', 'to' => '2026-09-30',
        'filters' => [['column' => 'final_amount', 'operator' => '>=', 'value' => '500']], 'sort_by' => 'final_amount', 'sort_dir' => 'desc',
    ]])->assertOk();

    expect($plain->json('data'))->toHaveCount(2)->and($plain->json('data.0.final_amount'))->toEqual(1000)->and(json_encode($plain->json()))->not->toContain('4242');

    $grouped = $this->postJson('/api/report-builder/preview', ['dataset' => 'sales', 'definition' => ['columns' => ['final_amount'], 'group_by' => 'created_by', 'sort_by' => 'final_amount', 'sort_dir' => 'desc']])->assertOk();
    expect($grouped->json('data.0'))->toMatchArray(['created_by' => 'Alice', 'final_amount' => 1000.0, 'row_count' => 1]);

    // nothing outside the whitelist reaches SQL
    $bad = fn (array $definition, string $dataset = 'sales') => $this->postJson('/api/report-builder/preview', ['dataset' => $dataset, 'definition' => $definition]);
    $bad(['columns' => ['password']])->assertUnprocessable();
    $bad(['columns' => ['invoice_no; drop table users']])->assertUnprocessable();
    $bad(['columns' => ['invoice_no'], 'filters' => [['column' => 'invoice_no', 'operator' => 'or 1=1', 'value' => 'x']]])->assertUnprocessable();
    $bad(['columns' => ['invoice_no'], 'group_by' => 'final_amount'])->assertUnprocessable();
    $bad(['columns' => ['x']], 'users')->assertUnprocessable();

    // a quote in a value is data, not SQL
    $this->postJson('/api/report-builder/preview', ['dataset' => 'sales', 'definition' => ['columns' => ['invoice_no'], 'filters' => [['column' => 'invoice_no', 'operator' => '=', 'value' => "x' OR '1'='1"]]]])
        ->assertOk()->assertJsonCount(0, 'data');
});

test('a saved custom report is private or shared, owner or admin edits it, and CSV cells that look like formulas are neutralised', function () {
    $scope = w4Sales();
    $alice = w4Staff($scope, ['/reportbuilder', '/reportbuilder/manage']);
    $bob = w4Staff($scope, ['/reportbuilder', '/reportbuilder/manage']);
    $definition = ['columns' => ['invoice_no', 'contact', 'final_amount'], 'from' => '2026-09-01', 'to' => '2026-09-30'];

    Sanctum::actingAs($alice);
    $private = $this->postJson('/api/report-builder', ['name' => 'Alice only', 'dataset' => 'sales', 'definition' => $definition])->assertCreated()->json('data.id');
    $shared = $this->postJson('/api/report-builder', ['name' => 'Everyone', 'dataset' => 'sales', 'definition' => $definition, 'is_shared' => true])->assertCreated()->json('data.id');

    Sanctum::actingAs($bob);
    $names = collect($this->getJson('/api/report-builder')->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Everyone']);
    $this->getJson("/api/report-builder/{$private}/run")->assertNotFound();
    $this->getJson("/api/report-builder/{$shared}/run")->assertOk();
    $this->deleteJson("/api/report-builder/{$shared}")->assertUnprocessable();

    // a customer name that starts like a formula
    DB::table('contacts')->where('id', $scope['customer_id'])->update(['business_name' => '=HYPERLINK("http://x","click")']);
    $csv = $this->get("/api/report-builder/{$shared}/run?format=csv")->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->and($csv)->not->toContain(',=HYPERLINK');

    Sanctum::actingAs($alice);
    $this->deleteJson("/api/report-builder/{$shared}")->assertOk();
    expect(DB::table('custom_reports')->whereNull('deleted_at')->count())->toBe(1);

    // another company cannot run it, even by id
    $outsider = w4Staff(trpScope('W4Q'), ['/reportbuilder']);
    Sanctum::actingAs($outsider);
    $this->getJson("/api/report-builder/{$private}/run")->assertNotFound();
});

test('the report builder is a plan feature that can be switched off', function () {
    $scope = trpScope('W4G');
    $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Lite', 'code' => 'LITE'.uniqid(), 'billing_cycle' => 'monthly', 'price' => 0, 'trial_days' => 0, 'is_active' => 1, 'features' => json_encode(['crm']), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('companies')->where('id', $scope['company_id'])->update(['subscription_plan_id' => $plan, 'tenant_status' => 'active']);
    Sanctum::actingAs(w4Staff($scope, ['/reportbuilder']));

    $this->getJson('/api/report-builder')->assertForbidden()->assertJsonPath('plan_feature_off', 'reports_builder');
    DB::table('subscription_plans')->where('id', $plan)->update(['features' => json_encode(['crm', 'reports_builder'])]);
    $this->getJson('/api/report-builder')->assertOk();
});

// ---------------------------------------------------------------------------------------------------------------------
// scheduled reports

test('a scheduled report is mailed as a CSV when due, once a day, and only while its creator may open it', function () {
    Mail::fake();
    $scope = w4Sales();
    $creator = w4Staff($scope, ['/reportbuilder', '/reportbuilder/manage', '/report/sales-by-salesperson']);
    Sanctum::actingAs($creator);

    $this->postJson('/api/report-schedules', ['name' => 'Bad key', 'report_type' => 'standard', 'report_key' => 'item-profit-loss', 'frequency' => 'daily', 'recipients' => ['boss@example.com']])->assertUnprocessable();
    $this->postJson('/api/report-schedules', ['name' => 'Bad mail', 'report_type' => 'standard', 'report_key' => 'sales-by-salesperson', 'frequency' => 'daily', 'recipients' => ['nope']])->assertUnprocessable();

    $id = $this->postJson('/api/report-schedules', [
        'name' => 'Daily sales', 'report_type' => 'standard', 'report_key' => 'sales-by-salesperson', 'frequency' => 'daily',
        'filters' => ['start_date' => '2026-09-01', 'end_date' => '2026-09-30'], 'recipients' => ['boss@example.com', 'boss@example.com', 'ops@example.com'],
    ])->assertCreated()->json('data.id');

    $scheduler = app(ReportScheduler::class);
    $first = $scheduler->runDue(Carbon::parse('2026-10-01 06:00:00'));
    $again = $scheduler->runDue(Carbon::parse('2026-10-01 18:00:00'));

    expect($first['sent'])->toBe(1)->and($again['sent'])->toBe(0);
    Mail::assertSent(ScheduledReportMail::class, 2);
    Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail): bool => $mail->hasTo('boss@example.com') && str_contains($mail->csv, 'Alice Seller') && str_contains($mail->csv, '900'));
    expect(DB::table('report_schedule_runs')->where('report_schedule_id', $id)->value('status'))->toBe('sent');

    // the creator loses the permission: the report is skipped, not sent
    DB::table('report_schedules')->where('id', $id)->update(['last_run_at' => null]);
    DB::table('permissions')->where('role_id', $creator->role_id)->whereIn('menu_id', DB::table('menus')->where('route_path', '/report/sales-by-salesperson')->pluck('id'))->delete();
    Cache::flush();
    Mail::fake();
    $skipped = $scheduler->runDue(Carbon::parse('2026-10-02 06:00:00'));

    expect($skipped['skipped'])->toBe(1)->and($skipped['sent'])->toBe(0);
    Mail::assertNothingSent();
});

test('weekly and monthly schedules wait for their day and a deactivated creator is skipped', function () {
    Mail::fake();
    $scope = w4Sales();
    $creator = w4Staff($scope, ['/reportbuilder', '/reportbuilder/manage', '/report/sales-forecast']);
    Sanctum::actingAs($creator);
    $weekly = $this->postJson('/api/report-schedules', ['name' => 'Weekly', 'report_type' => 'standard', 'report_key' => 'sales-forecast', 'frequency' => 'weekly', 'weekday' => 1, 'recipients' => ['a@example.com']])->assertCreated()->json('data.id');
    $monthly = $this->postJson('/api/report-schedules', ['name' => 'Monthly', 'report_type' => 'standard', 'report_key' => 'sales-forecast', 'frequency' => 'monthly', 'day_of_month' => 31, 'recipients' => ['a@example.com']])->assertCreated()->json('data.id');
    $scheduler = app(ReportScheduler::class);

    // Tuesday 2026-10-13: neither is due
    expect($scheduler->runDue(Carbon::parse('2026-10-13 06:00:00')))->toMatchArray(['sent' => 0]);
    // Monday 2026-10-12: the weekly one is due. 30 November is a Monday and the last day of a 30-day month, so both are due
    expect($scheduler->runDue(Carbon::parse('2026-10-12 06:00:00'))['sent'])->toBe(1);
    expect($scheduler->runDue(Carbon::parse('2026-11-30 06:00:00'))['sent'])->toBe(2);

    DB::table('users')->where('id', $creator->id)->update(['is_active' => 0]);
    DB::table('report_schedules')->update(['last_run_at' => null]);
    $result = $scheduler->runDue(Carbon::parse('2026-11-30 07:00:00'));
    expect($result['sent'])->toBe(0)->and($result['skipped'])->toBe(2)->and($weekly + $monthly)->toBeInt();
});

test('schedules are the creator own, other companies cannot see them and a company id in the body is ignored', function () {
    $a = w4Sales();
    $b = trpScope('W4Y');
    $alice = w4Staff($a, ['/reportbuilder', '/reportbuilder/manage', '/report/sales-by-salesperson']);
    $outsider = w4Staff($b, ['/reportbuilder', '/reportbuilder/manage', '/report/sales-by-salesperson']);

    Sanctum::actingAs($alice);
    $id = $this->postJson('/api/report-schedules', [
        'name' => 'Mine', 'report_type' => 'standard', 'report_key' => 'sales-by-salesperson', 'frequency' => 'daily', 'recipients' => ['a@example.com'], 'company_id' => $b['company_id'],
    ])->assertCreated()->json('data.id');
    expect((int) DB::table('report_schedules')->where('id', $id)->value('company_id'))->toBe($a['company_id']);

    Sanctum::actingAs($outsider);
    $this->getJson('/api/report-schedules')->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson("/api/report-schedules/{$id}")->assertNotFound();
    $this->postJson("/api/report-schedules/{$id}/run")->assertNotFound();
    $this->putJson("/api/report-schedules/{$id}", ['is_active' => false])->assertNotFound();
});

test('the analytics overview is read only and company scoped', function () {
    $mine = w4Sales();
    $theirs = trpScope('W4N');
    trpDoc($theirs, 'sell', ['final_amount' => 31337, 'transaction_date' => '2026-09-10 10:00:00']);
    Sanctum::actingAs(w4Staff($mine, ['/analytics']));

    $response = $this->getJson('/api/analytics-overview?from=2026-09-01&to=2026-09-30')->assertOk();
    expect($response->json('data.monthly_sales'))->toHaveCount(6)->and(json_encode($response->json()))->not->toContain('31337')
        ->and(collect($response->json('data.by_salesperson'))->pluck('name')->all())->toContain('Alice Seller');

    Sanctum::actingAs(w4Staff($mine, []));
    $this->getJson('/api/analytics-overview')->assertForbidden();
});
