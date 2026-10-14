<?php

use App\Models\User;
use App\Services\Company\CompanyBootstrap;
use App\Services\Manufacturing\ManufacturingSettings;
use App\Services\Reports\StockValuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The balance sheet carries the closing stock as an asset and takes the profit and loss's own net profit, so the two statements agree
 * (see BalanceSheetReport). Every company here is a fresh sign-up: its own chart, no legacy rows.
 *
 * @return array{company: array<string, int>, company_id: int, branch_id: int, accounts: array<string, int>, product: object, variation: int, unit: int, supplier: object, customer: object, user: User}
 */
function bsCompany(string $name = 'Stock Traders'): array
{
    config(['landing.registration_enabled' => true]);
    test()->artisan('install:baseline-menus')->assertSuccessful();
    $slug = strtolower(str_replace(' ', '', $name));

    test()->post('/register', [
        'company_name' => $name, 'first_name' => 'Owner', 'username' => $slug, 'email' => "{$slug}@bs.test", 'password' => 'Str0ng!Pass', 'password_confirmation' => 'Str0ng!Pass',
    ])->assertRedirect();

    $user = User::query()->where('email', "{$slug}@bs.test")->firstOrFail();
    $companyId = (int) $user->company_id;
    $branchId = (int) DB::table('branches')->where('company_id', $companyId)->value('id');
    Sanctum::actingAs($user, ['*']);
    $company = ['company_id' => $companyId, 'branch_id' => $branchId];
    $unit = (int) DB::table('units')->where('company_id', $companyId)->value('id');

    test()->postJson('/api/products', $company + [
        'name' => 'Rice 5kg', 'unit_id' => $unit, 'brand_id' => (int) DB::table('brands')->where('company_id', $companyId)->value('id'),
        'category_id' => (int) DB::table('categories')->where('company_id', $companyId)->value('id'), 'itemtype_id' => (int) DB::table('item_types')->where('company_id', $companyId)->value('id'),
        'type' => 'single', 'alert_qty' => 5, 'active' => true,
        'productdetail' => [['variation_name' => 'dummy', 'default_purchase_price' => 100, 'profit_percent' => 20, 'default_sell_price' => 120, 'largequantity' => 0, 'smallquantity' => 0]],
    ])->assertSuccessful();

    foreach (['supplier' => 'Mills', 'customer' => 'Store'] as $type => $business) {
        test()->postJson($type === 'supplier' ? '/api/suppliers' : '/api/customers', $company + [
            'business_name' => $business, 'first_name' => $business, 'mobile' => '0300'.random_int(1000000, 9999999), 'user_type' => $type, 'type' => 'local', 'pay_type' => 'day', 'active' => true,
            'address' => 'Karachi', 'ntn_number' => '1234567-8',
        ])->assertSuccessful();
    }

    $product = DB::table('products')->where('company_id', $companyId)->first();

    return [
        'company' => $company, 'company_id' => $companyId, 'branch_id' => $branchId, 'accounts' => app(CompanyBootstrap::class)->accountIds($companyId, $branchId), 'product' => $product,
        'variation' => (int) DB::table('product_details')->where('product_id', $product->id)->value('id'), 'unit' => $unit,
        'supplier' => DB::table('contacts')->where('company_id', $companyId)->where('user_type', 'supplier')->first(),
        'customer' => DB::table('contacts')->where('company_id', $companyId)->where('user_type', 'customer')->first(), 'user' => $user,
    ];
}

function bsPurchase(array $c, int $quantity, float $rate, string $date): int
{
    test()->postJson('/api/purchases', $c['company'] + [
        'contact_id' => $c['supplier']->id, 'transaction_date' => $date, 'pay_term' => 15, 'pay_type' => 'day', 'discount_type' => 'none', 'discount_amount' => 0, 'shipping_charges' => 0,
        'final_amount' => $quantity * $rate, 'total_item' => 1, 'is_direct' => false,
        'purchaselines' => [['product_id' => $c['product']->id, 'variation_id' => $c['variation'], 'unit_id' => $c['unit'], 'quantity' => $quantity, 'packing_qty' => 1, 'pp_without_discount' => $rate, 'discount_percent' => 0, 'purchase_rate' => $rate, 'profit_percent' => 20, 'default_sell_price' => 120]],
    ])->assertSuccessful();
    $id = (int) DB::table('transactions')->where('company_id', $c['company_id'])->where('type', 'purchaseorder')->max('id');

    if (DB::table('transactions')->where('id', $id)->value('status') === 'pending') {
        test()->postJson("/api/purchase-approvals/{$id}/approve")->assertSuccessful();
    }

    test()->postJson('/api/receiving-notes', $c['company'] + [
        'contact_id' => $c['supplier']->id, 'transaction_id' => $id,
        'purchaselines' => DB::table('purchase_lines')->where('transaction_id', $id)->get(['id', 'quantity'])->map(fn ($l) => ['id' => $l->id, 'quantity_received' => $l->quantity])->all(),
    ])->assertSuccessful();

    return $id;
}

function bsSale(array $c, int $quantity, float $price, string $date): int
{
    $response = test()->postJson('/api/sells', $c['company'] + [
        'contact_id' => $c['customer']->id, 'transaction_date' => $date, 'pay_term' => 10, 'pay_type' => 'day', 'status' => 'final', 'discount_type' => 'none', 'discount_amount' => 0,
        'shipping_charges' => 0, 'final_amount' => $quantity * $price, 'total_item' => 1, 'is_direct' => false,
        'selllines' => [['product_id' => $c['product']->id, 'variation_id' => $c['variation'], 'unit_id' => $c['unit'], 'quantity' => $quantity, 'packing_qty' => 1, 'unit_price' => $price, 'discount_percent' => 0, 'unit_price_after_discount' => $price, 'row_subtotal' => $quantity * $price]],
    ])->assertSuccessful();
    $id = (int) $response->json('id');

    if (DB::table('transactions')->where('id', $id)->value('status') !== 'approved') {
        test()->postJson("/api/sell-approvals/{$id}/approve")->assertSuccessful();
    }

    test()->postJson('/api/issue-notes', $c['company'] + [
        'contact_id' => $c['customer']->id, 'transaction_id' => $id,
        'selllines' => DB::table('sell_lines')->where('transaction_id', $id)->get(['id', 'quantity'])->map(fn ($l) => ['id' => $l->id, 'quantity_issue' => $l->quantity])->all(),
    ])->assertSuccessful();

    return $id;
}

/**
 * @return array<string, mixed>
 */
function bsSheet(array $c, string $endDate, string $path = 'balance-sheet', array $extra = []): array
{
    return test()->getJson("/api/reports/{$path}?".http_build_query($c['company'] + ['end_date' => $endDate, 'show_record' => 200] + $extra))->assertSuccessful()->json();
}

function bsRow(array $sheet, string $name): ?array
{
    return collect($sheet['data']['data'])->firstWhere('name', $name);
}

function bsJournal(array $c, array $lines): int
{
    test()->postJson('/api/journal-entries', $c['company'] + [
        'voucher_type' => 'JV', 'voucher_date' => now()->toDateString(), 'comments' => 'Stock journal',
        'taccountdetails' => collect($lines)->map(fn (array $l) => ['account_id' => $l[0], 'code' => DB::table('chart_of_accounts')->where('id', $l[0])->value('code'), 'account_nature' => $l[1], 'description' => 'x', 'debit' => $l[1] === 'dr' ? $l[2] : 0, 'credit' => $l[1] === 'cr' ? $l[2] : 0])->all(),
    ])->assertSuccessful();
    $voucher = DB::table('t_accounts')->where('company_id', $c['company_id'])->where('voucher_no', 'like', 'JV-%')->orderByDesc('id')->first();

    if ($voucher->status === 'pending') {
        test()->postJson("/api/journal-entry-approvals/{$voucher->id}/approve")->assertSuccessful();
    }

    return (int) $voucher->id;
}

it('proves the old gap: the balance sheet profit and the P&L net profit differ by exactly the closing stock', function () {
    $c = bsCompany();
    $today = now()->toDateString();
    bsPurchase($c, 50, 100, $today);
    bsSale($c, 20, 120, $today);

    $sheet = bsSheet($c, $today);
    $pnl = test()->getJson('/api/reports/profit-loss?'.http_build_query($c['company'] + ['end_date' => $today]))->assertSuccessful()->json('summary');

    // what the ledger alone says: 2400 revenue less 5000 purchases = -2600 (the old balance sheet profit); the P&L deducts only the
    // 2000 of goods sold: 2400 - (0 + 5000 - 3000 closing stock) = 400
    expect((float) $sheet['summary']['ledger_profit'])->toBe(-2600.0)
        ->and((float) $pnl['net_profit'])->toBe(400.0)
        ->and((float) $pnl['closing_stock'])->toBe(3000.0)
        // the difference is the closing stock, to the cent (there is no opening stock: the company is new)
        ->and(round((float) $pnl['net_profit'] - (float) $sheet['summary']['ledger_profit'], 2))->toBe((float) $pnl['closing_stock'] - (float) $pnl['opening_stock']);
});

it('puts the closing stock in assets and the P&L net profit in equity, and the sheet balances', function () {
    $c = bsCompany();
    $today = now()->toDateString();
    bsPurchase($c, 50, 100, $today);
    bsSale($c, 20, 120, $today);

    $sheet = bsSheet($c, $today);
    $summary = $sheet['summary'];
    $pnl = test()->getJson('/api/reports/profit-loss?'.http_build_query($c['company'] + ['end_date' => $today]))->assertSuccessful()->json('summary');

    // before: assets 2400 (the receivable) = liabilities 5000 + a loss of 2600, no stock anywhere
    // after:  assets 2400 + stock 3000 = 5400 = liabilities 5000 + profit 400 (the P&L's net profit), nothing brought forward
    expect($summary['includes_stock'])->toBeTrue()->and((float) $summary['closing_stock'])->toBe(3000.0)->and((float) $summary['opening_stock'])->toBe(0.0)
        ->and((float) $summary['profit'])->toBe((float) $pnl['net_profit'])->and((float) $summary['total_assets'])->toBe(5400.0)
        ->and((float) $summary['total_equity'])->toBe(400.0)->and((float) $summary['difference'])->toBe(0.0)->and($summary['is_balanced'])->toBeTrue()
        ->and(round((float) $summary['total_assets'], 2))->toBe(round((float) $summary['total_liabilities'] + (float) $summary['total_equity'], 2));

    expect(bsRow($sheet, 'Closing stock (inventory at cost)')['amount'])->toEqual(3000)
        ->and(bsRow($sheet, 'Stock brought forward (start of year)'))->toBeNull();

    // the stock line is the Stock Report's own total, not a second calculation
    $stockReport = test()->getJson('/api/reports/stock?'.http_build_query($c['company'] + ['end_date' => $today, 'show_record' => 100]))->assertSuccessful()->json('data.data.0');
    expect((float) $stockReport['stock_value'])->toBe((float) $summary['closing_stock'])
        ->and((float) $summary['closing_stock'])->toBe(app(StockValuation::class)->at($c['company_id'], null, $today)['value']);
});

it('shows each column of the comparative sheet with the stock of its own day, both balanced', function () {
    $c = bsCompany();
    $earlier = now()->subDays(10)->toDateString();
    $today = now()->toDateString();
    bsPurchase($c, 50, 100, $earlier);
    bsSale($c, 30, 120, $today);

    $sheet = bsSheet($c, $today, 'balance-sheet-comparative', ['compare_end' => $earlier]);
    $row = bsRow($sheet, 'Closing stock (inventory at cost)');

    // 50 bought at 100 on the first day; 30 of them sold by the second
    expect($row['previous'])->toEqual(5000)->and($row['current'])->toEqual(2000)
        ->and((float) $sheet['summary']['difference'])->toBe(0.0)->and((float) $sheet['summary']['difference_previous'])->toBe(0.0)
        ->and((float) $sheet['summary']['inventory'])->toBe(2000.0)->and((float) $sheet['summary']['inventory_previous'])->toBe(5000.0);

    // each column is the single-day sheet of that day
    expect((float) bsSheet($c, $earlier)['summary']['total_assets'])->toBe((float) $sheet['summary']['total_assets_previous'])
        ->and((float) bsSheet($c, $today)['summary']['total_assets'])->toBe((float) $sheet['summary']['total_assets']);
});

it('values the stock line with the method in force on the day (FIFO)', function () {
    $c = bsCompany();
    $today = now()->toDateString();
    DB::table('valuation_method_changes')->insert(['company_id' => $c['company_id'], 'method' => 'fifo', 'effective_from' => now()->subYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    bsPurchase($c, 10, 100, now()->subDays(5)->toDateString());
    bsPurchase($c, 10, 140, now()->subDays(3)->toDateString());
    bsSale($c, 10, 200, $today);

    $sheet = bsSheet($c, $today);

    // FIFO sold the 100 layer: the 10 left cost 140 each (an average of 120 would give 1200)
    expect((float) $sheet['summary']['closing_stock'])->toBe(1400.0)->and($sheet['summary']['stock_method'])->toBe('fifo')
        ->and((float) $sheet['summary']['difference'])->toBe(0.0);
});

it('keeps a company with no stock exactly as it was: no stock lines, the ledger profit', function () {
    $c = bsCompany();
    $today = now()->toDateString();
    bsJournal($c, [[$c['accounts']['cash'], 'dr', 10000], [$c['accounts']['capital'], 'cr', 10000]]);
    bsJournal($c, [[$c['accounts']['bank'], 'dr', 3000], [$c['accounts']['local_sales'], 'cr', 3000]]);
    bsJournal($c, [[$c['accounts']['rent'], 'dr', 1000], [$c['accounts']['cash'], 'cr', 1000]]);

    $sheet = bsSheet($c, $today);
    $summary = $sheet['summary'];

    expect($summary['includes_stock'])->toBeFalse()->and((float) $summary['profit'])->toBe(2000.0)->and((float) $summary['profit'])->toBe((float) $summary['ledger_profit'])
        ->and((float) $summary['total_assets'])->toBe(12000.0)->and((float) $summary['difference'])->toBe(0.0)
        ->and(collect($sheet['data']['data'])->contains(fn (array $row): bool => $row['line'] === 'stock'))->toBeFalse();
});

it('shows no stock line when the company already journals its stock (no double counting)', function () {
    $c = bsCompany();
    $today = now()->toDateString();
    bsPurchase($c, 50, 100, $today);
    bsSale($c, 20, 120, $today);

    // the books have a stock account with a posting, mapped as the inventory account
    DB::table('chart_of_account_mappings')->updateOrInsert(
        ['company_id' => $c['company_id'], 'branch_id' => $c['branch_id'], 'key' => 'inventory'],
        ['name' => 'Inventory', 'value' => (string) $c['accounts']['inventory'], 'created_at' => now(), 'updated_at' => now()],
    );
    $ledgerOnly = bsSheet($c, $today)['summary'];
    expect($ledgerOnly['includes_stock'])->toBeTrue(); // mapped but nothing posted to it yet

    bsJournal($c, [[$c['accounts']['inventory'], 'dr', 3000], [$c['accounts']['purchases'], 'cr', 3000]]);
    $sheet = bsSheet($c, $today);

    expect($sheet['summary']['includes_stock'])->toBeFalse()->and((float) $sheet['summary']['closing_stock'])->toBe(0.0)
        ->and((float) $sheet['summary']['profit'])->toBe((float) $sheet['summary']['ledger_profit'])
        // the stock is counted once, from the ledger account
        ->and(bsRow($sheet, 'Stock in Trade')['amount'])->toEqual(3000)
        ->and(bsRow($sheet, 'Closing stock (inventory at cost)'))->toBeNull()
        ->and((float) $sheet['summary']['difference'])->toBe(0.0);
});

it('shows no stock line when the manufacturing inventory accounts carry postings', function () {
    $c = bsCompany();
    $today = now()->toDateString();
    bsPurchase($c, 10, 100, $today);
    app(ManufacturingSettings::class)->save($c['company_id'], ['posting_enabled' => true, 'accounts' => ['raw_material_inventory' => $c['accounts']['inventory']]]);
    bsJournal($c, [[$c['accounts']['inventory'], 'dr', 500], [$c['accounts']['capital'], 'cr', 500]]);

    $sheet = bsSheet($c, $today);

    expect($sheet['summary']['includes_stock'])->toBeFalse()->and((float) $sheet['summary']['profit'])->toBe((float) $sheet['summary']['ledger_profit']);
});

it('adds the stock brought forward to equity when the year opened with stock, and still balances', function () {
    $c = bsCompany();
    $today = now()->toDateString();
    $year = DB::table('financial_years')->where('company_id', $c['company_id'])->where('status', 1)->first();
    $yearStart = substr((string) $year->start_date, 0, 10);
    $dayBefore = now()->parse($yearStart)->subDays(5)->toDateString();

    // 40 bags bought in the previous year, fully owed; that year was closed: the purchase went to retained earnings (a debit of 4000)
    bsPurchase($c, 40, 100, $dayBefore);
    $supplierCoa = (int) DB::table('chart_of_accounts')->where('company_id', $c['company_id'])->where('code', $c['supplier']->supplier_gl_id)->value('id');
    DB::table('account_balances')->insert([
        ['company_id' => $c['company_id'], 'branch_id' => $c['branch_id'], 'financial_id' => $year->id, 'coa_id' => $supplierCoa, 'opening_balance' => 4000, 'acc_nature' => 'cr', 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $c['company_id'], 'branch_id' => $c['branch_id'], 'financial_id' => $year->id, 'coa_id' => $c['accounts']['pnl'], 'opening_balance' => 4000, 'acc_nature' => 'dr', 'created_at' => now(), 'updated_at' => now()],
    ]);
    bsSale($c, 10, 120, $today);

    $sheet = bsSheet($c, $today);
    $pnl = test()->getJson('/api/reports/profit-loss?'.http_build_query($c['company'] + ['start_date' => $yearStart, 'end_date' => $today]))->assertSuccessful()->json('summary');

    // opening 40 x 100, closing 30 x 100; revenue 1200; no purchases in the year: cost of goods 1000, profit 200
    expect((float) $pnl['opening_stock'])->toBe(4000.0)->and((float) $pnl['closing_stock'])->toBe(3000.0)->and((float) $pnl['net_profit'])->toBe(200.0)
        ->and((float) $sheet['summary']['profit'])->toBe(200.0)
        ->and(bsRow($sheet, 'Stock brought forward (start of year)')['amount'])->toEqual(4000)
        ->and(bsRow($sheet, 'Closing stock (inventory at cost)')['amount'])->toEqual(3000)
        ->and((float) $sheet['summary']['difference'])->toBe(0.0);
});

it('computes the financial ratios on the sheet that carries the stock, with the profit counted once in equity', function () {
    $c = bsCompany();
    $today = now()->toDateString();
    bsPurchase($c, 50, 100, $today);
    bsSale($c, 20, 120, $today);

    $ratios = collect(test()->getJson('/api/reports/financial-ratios?'.http_build_query($c['company'] + ['end_date' => $today]))->assertSuccessful()->json('data.data'))->pluck('value', 'label');

    // assets 5400 = liabilities 5000 + equity 400 (before: equity was the profit added a second time)
    expect($ratios['Debt ratio'])->toEqual(92.59)->and($ratios['Equity ratio'])->toEqual(7.41)->and($ratios['Debt to equity'])->toEqual(12.5);
});
