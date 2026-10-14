<?php

use App\Models\User;
use App\Services\Company\CompanyBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * A company that signs up on the public page, on a database that only ran the migrations (no menu dump, no legacy rows), must be able
 * to run the core flows with the permissions of its own admin: nothing here is granted by hand and nothing is done as the superadmin.
 */
function freshSignup(): array
{
    config(['landing.registration_enabled' => true]);
    test()->artisan('install:baseline-menus')->assertSuccessful();

    test()->post('/register', [
        'company_name' => 'Fresh Traders', 'company_email' => 'info@fresh.test', 'first_name' => 'Sana', 'last_name' => 'Malik', 'username' => 'sana.fresh',
        'email' => 'sana@fresh.test', 'password' => 'Str0ng!Pass', 'password_confirmation' => 'Str0ng!Pass',
    ])->assertRedirect();

    $user = User::query()->where('email', 'sana@fresh.test')->firstOrFail();
    $companyId = (int) $user->company_id;
    $branchId = (int) DB::table('branches')->where('company_id', $companyId)->value('id');
    $accounts = app(CompanyBootstrap::class)->accountIds($companyId, $branchId);

    Sanctum::actingAs($user, ['*']);

    return ['user' => $user, 'company_id' => $companyId, 'branch_id' => $branchId, 'accounts' => $accounts];
}

function freshPost(string $uri, array $payload): TestResponse
{
    return test()->postJson($uri, $payload);
}

test('a company that signs up gets a usable set-up with no legacy data', function () {
    $c = freshSignup();

    // role and permissions
    $role = DB::table('roles')->where('id', $c['user']->role_id)->first();
    expect($role->name)->toBe('companyadmin')
        ->and(DB::table('permissions')->where('role_id', $role->id)->where('status', 1)->count())->toBeGreaterThan(300)
        ->and($c['user']->getPermissionPaths())->toContain('/purchase', '/sell', '/journalentry', '/customer', '/product');

    // the staff presets, the branch, the withholding accounts
    expect(DB::table('roles')->where('company_id', $c['company_id'])->pluck('name')->all())->toContain('Accountant', 'Cashier', 'Manager');
    expect(DB::table('chart_of_accounts')->where('company_id', $c['company_id'])->whereIn('code', ['200-00001', '300-00001'])->count())->toBe(2);

    // chart of accounts and mappings: every posting key points at a real account of this company
    expect(DB::table('chart_of_accounts')->where('company_id', $c['company_id'])->count())->toBeGreaterThan(30);
    $mappings = DB::table('chart_of_account_mappings')->where('company_id', $c['company_id'])->pluck('value', 'key');

    foreach (['customer', 'supplier', 'bank', 'cash', 'purchase', 'localpurchase', 'inputtax', 'sale', 'localsales', 'exportsale', 'outputtax', 'equity', 'assets', 'liability', 'expenses', 'revenue', 'pnl'] as $key) {
        expect($mappings[$key] ?? null)->not->toBeEmpty("mapping {$key} is empty")
            ->and(DB::table('chart_of_accounts')->where('company_id', $c['company_id'])->where('id', (int) $mappings[$key])->exists())->toBeTrue();
    }

    // an open financial year and the starting catalogue lists
    expect(DB::table('financial_years')->where('company_id', $c['company_id'])->where('status', 1)->count())->toBe(1);

    foreach (['units', 'brands', 'categories', 'item_types'] as $table) {
        expect(DB::table($table)->where('company_id', $c['company_id'])->count())->toBeGreaterThan(0, "no default in {$table}");
    }
});

test('the first sale, purchase, return and journal of a new company work and its books balance', function () {
    $c = freshSignup();
    $company = ['company_id' => $c['company_id'], 'branch_id' => $c['branch_id']];
    $unit = (int) DB::table('units')->where('company_id', $c['company_id'])->value('id');

    // product
    freshPost('/api/products', $company + [
        'name' => 'Basmati Rice 5kg', 'unit_id' => $unit, 'brand_id' => (int) DB::table('brands')->where('company_id', $c['company_id'])->value('id'),
        'category_id' => (int) DB::table('categories')->where('company_id', $c['company_id'])->value('id'), 'itemtype_id' => (int) DB::table('item_types')->where('company_id', $c['company_id'])->value('id'),
        'type' => 'single', 'alert_qty' => 5, 'active' => true,
        'productdetail' => [['variation_name' => 'dummy', 'default_purchase_price' => 100, 'profit_percent' => 20, 'default_sell_price' => 120, 'largequantity' => 0, 'smallquantity' => 0]],
    ])->assertSuccessful();
    $product = DB::table('products')->where('company_id', $c['company_id'])->where('name', 'Basmati Rice 5kg')->first();
    $variation = (int) DB::table('product_details')->where('product_id', $product->id)->value('id');

    // supplier and customer get their own ledger accounts under the mapped groups
    freshPost('/api/suppliers', $company + ['business_name' => 'Rice Mills', 'first_name' => 'Aslam', 'mobile' => '03001234567', 'user_type' => 'supplier', 'type' => 'local', 'pay_type' => 'day', 'active' => true, 'address' => 'Gujranwala', 'ntn_number' => '1234567-8'])->assertSuccessful();
    freshPost('/api/customers', $company + ['business_name' => 'City Store', 'first_name' => 'Nadia', 'mobile' => '03007654321', 'user_type' => 'customer', 'type' => 'local', 'pay_type' => 'day', 'active' => true, 'address' => 'Karachi', 'ntn_number' => '7654321-0'])->assertSuccessful();
    $supplier = DB::table('contacts')->where('company_id', $c['company_id'])->where('user_type', 'supplier')->first();
    $customer = DB::table('contacts')->where('company_id', $c['company_id'])->where('user_type', 'customer')->first();
    expect($supplier->supplier_gl_id)->not->toBeEmpty()->and($customer->customer_gl_id)->not->toBeEmpty();

    // purchase and receive 50 at 100
    freshPost('/api/purchases', $company + [
        'contact_id' => $supplier->id, 'transaction_date' => now()->toDateString(), 'pay_term' => 15, 'pay_type' => 'day', 'discount_type' => 'none', 'discount_amount' => 0, 'shipping_charges' => 0,
        'final_amount' => 5000, 'total_item' => 1, 'is_direct' => false,
        'purchaselines' => [['product_id' => $product->id, 'variation_id' => $variation, 'unit_id' => $unit, 'quantity' => 50, 'packing_qty' => 1, 'pp_without_discount' => 100, 'discount_percent' => 0, 'purchase_rate' => 100, 'profit_percent' => 20, 'default_sell_price' => 120]],
    ])->assertSuccessful();
    $purchase = DB::table('transactions')->where('company_id', $c['company_id'])->where('type', 'purchaseorder')->first();

    if ($purchase->status === 'pending') {
        test()->postJson("/api/purchase-approvals/{$purchase->id}/approve")->assertSuccessful();
    }

    freshPost('/api/receiving-notes', $company + [
        'contact_id' => $supplier->id, 'transaction_id' => $purchase->id,
        'purchaselines' => DB::table('purchase_lines')->where('transaction_id', $purchase->id)->get(['id', 'quantity'])->map(fn ($l) => ['id' => $l->id, 'quantity_received' => $l->quantity])->all(),
    ])->assertSuccessful();

    // sell 20 at 120, issue, take 1000 in cash
    $sale = test()->postJson('/api/sells', $company + [
        'contact_id' => $customer->id, 'transaction_date' => now()->toDateString(), 'pay_term' => 10, 'pay_type' => 'day', 'status' => 'final', 'discount_type' => 'none', 'discount_amount' => 0,
        'shipping_charges' => 0, 'final_amount' => 2400, 'total_item' => 1, 'is_direct' => false,
        'selllines' => [['product_id' => $product->id, 'variation_id' => $variation, 'unit_id' => $unit, 'quantity' => 20, 'packing_qty' => 1, 'unit_price' => 120, 'discount_percent' => 0, 'unit_price_after_discount' => 120, 'row_subtotal' => 2400]],
    ])->assertSuccessful();
    $saleId = (int) $sale->json('id');

    if (DB::table('transactions')->where('id', $saleId)->value('status') !== 'approved') {
        test()->postJson("/api/sell-approvals/{$saleId}/approve")->assertSuccessful();
    }

    freshPost('/api/issue-notes', $company + [
        'contact_id' => $customer->id, 'transaction_id' => $saleId,
        'selllines' => DB::table('sell_lines')->where('transaction_id', $saleId)->get(['id', 'quantity'])->map(fn ($l) => ['id' => $l->id, 'quantity_issue' => $l->quantity])->all(),
    ])->assertSuccessful();
    freshPost('/api/sell-payments', $company + ['contact_id' => $customer->id, 'transaction_id' => $saleId, 'amount' => 1000, 'paid_on' => now()->toDateString(), 'method' => 'cash', 'payment_account' => $c['accounts']['cash']])->assertSuccessful();

    // the customer returns 2 bags
    freshPost('/api/sell-returns', $company + [
        'contact_id' => $customer->id, 'transaction_id' => $saleId, 'transaction_date' => now()->toDateString(),
        'selllines' => [['id' => DB::table('sell_lines')->where('transaction_id', $saleId)->value('id'), 'quantity_returned' => 2]],
    ])->assertSuccessful();

    // an owner's capital journal
    freshPost('/api/journal-entries', $company + [
        'voucher_type' => 'JV', 'voucher_date' => now()->toDateString(), 'comments' => 'Opening capital',
        'taccountdetails' => [
            ['account_id' => $c['accounts']['cash'], 'code' => '212-00001', 'account_nature' => 'dr', 'description' => 'Cash', 'debit' => 50000, 'credit' => 0],
            ['account_id' => $c['accounts']['capital'], 'code' => '110-00001', 'account_nature' => 'cr', 'description' => 'Capital', 'debit' => 0, 'credit' => 50000],
        ],
    ])->assertSuccessful();

    // the books: every posted voucher balances, sale and return are in revenue, the stock report sees 30 bags
    $totals = DB::table('t_account_details as d')->join('t_accounts as t', 't.id', '=', 'd.t_account_id')->where('t.company_id', $c['company_id'])
        ->selectRaw('coalesce(sum(d.debit), 0) as debit, coalesce(sum(d.credit), 0) as credit')->first();
    expect((float) $totals->debit)->toBeGreaterThan(0)->and(round((float) $totals->debit, 2))->toBe(round((float) $totals->credit, 2));

    $trial = test()->getJson('/api/reports/trial-balance?'.http_build_query($company + ['start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->toDateString(), 'show_record' => 200]));
    $trial->assertSuccessful();

    $profit = test()->getJson('/api/reports/profit-loss?'.http_build_query($company + ['start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->toDateString()]));
    $profit->assertSuccessful();

    $balanceSheet = test()->getJson('/api/reports/balance-sheet?'.http_build_query($company + ['end_date' => now()->toDateString()]));
    $balanceSheet->assertSuccessful();

    $stock = test()->getJson('/api/reports/stock?'.http_build_query($company + ['end_date' => now()->toDateString(), 'show_record' => 100]));
    $stock->assertSuccessful();

    // the journal waits for approval (a new company starts with journal approval on); approve it and it is in the books
    $journal = DB::table('t_accounts')->where('company_id', $c['company_id'])->where('voucher_no', 'like', 'JV-%')->first();
    expect($journal->status)->toBe('pending');
    test()->postJson("/api/journal-entry-approvals/{$journal->id}/approve")->assertSuccessful();

    $filters = $company + ['start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->toDateString()];
    $trial = test()->getJson('/api/reports/trial-balance?'.http_build_query($filters + ['show_record' => 200]))->assertSuccessful();
    $profit = test()->getJson('/api/reports/profit-loss?'.http_build_query($filters))->assertSuccessful();
    $sheet = test()->getJson('/api/reports/balance-sheet?'.http_build_query($filters))->assertSuccessful();
    $stock = test()->getJson('/api/reports/stock?'.http_build_query($filters + ['show_record' => 100]))->assertSuccessful();

    // trial balance and balance sheet balance; capital and cash are in them
    expect($trial->json('summary.is_balanced'))->toBeTrue()->and($sheet->json('summary.is_balanced'))->toBeTrue()
        // changed when the sheet began to carry stock: assets were 52160 (the ledger) and are now 55360, the 3200 being the 32 bags left at
        // cost 100; equity gained the same 3200 (the profit is now the P&L net profit 360 instead of the ledger's -2840)
        ->and((float) $sheet->json('summary.total_assets'))->toBe(55360.0)
        ->and((float) $sheet->json('summary.total_liabilities'))->toBe(5000.0)
        ->and((float) $sheet->json('summary.profit'))->toBe(360.0);

    // 20 sold at 120 less the 2 returned = 2160 revenue; cost of goods = 5000 purchases less 32 bags left at 100
    $revenue = collect($profit->json('data.data'))->firstWhere('name', 'Total revenue');
    $cogs = collect($profit->json('data.data'))->firstWhere('name', 'Total cost of goods sold');
    expect((float) $revenue['amount'])->toBe(2160.0)->and((float) $cogs['amount'])->toBe(1800.0);

    // the stock report sees 50 bought, 20 sold, 2 back: 32 on hand at cost 100
    $row = $stock->json('data.data.0');
    expect($row['product_name'])->toBe('Basmati Rice 5kg')->and((float) $row['current_stock'])->toBe(32.0)->and((float) $row['stock_value'])->toBe(3200.0);
});
