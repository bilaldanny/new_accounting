<?php

use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountsVoucher;
use App\Services\Dashboard\DailyBusinessSummary;
use App\Services\Dashboard\DashboardLayouts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 1b, the dashboard rows wave 1 left open: the universal search bar, the daily business summary, layouts by role, the KPI widget
 * builder and the date range / warehouse filters of the sales cards.
 */
function w1bStaff(array $scope, array $paths, ?string $roleName = null): User
{
    $role = Role::query()->create(['name' => $roleName ?? 'w1b_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => null, 'email_verified_at' => now(), 'email' => 'w1b-'.uniqid().'@example.test']);
}

function w1bSale(array $scope, array $attributes = []): int
{
    return (int) createSellRecord($scope, $attributes + ['invoice_no' => 'INV-'.strtoupper(uniqid()), 'final_amount' => 500, 'status' => 'final', 'transaction_date' => now()->toDateString()])->id;
}

// ---------------------------------------------------------------------------------------------------------------------
// universal search

test('the search bar finds pages, contacts, products and documents the user may open, inside their company only', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $theirs = Contact::query()->create(['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'business_name' => 'Acme Foreign', 'first_name' => 'X', 'mobile' => '0300', 'address' => 'x', 'code' => 'CU-F1', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1', 'pay_term' => 1, 'pay_type' => 'day', 'credit_limit' => 1, 'active' => true]);
    w1bSale($scope, ['invoice_no' => 'ACME-INV-1']);
    $lamp = prdProduct($scope, 'Acme lamp');
    prdProduct($other, 'Acme foreign lamp');
    Sanctum::actingAs(w1bStaff($scope, ['/customer', '/product', '/sell', '/report/stock']));

    $found = collect($this->getJson('/api/search?q=acme')->assertOk()->json('data'));
    $labels = $found->pluck('label')->all();

    expect($labels)->toContain('Acme Retail')->toContain('Acme lamp')->toContain('ACME-INV-1')->not->toContain('Acme Foreign')->not->toContain('Acme foreign lamp')
        ->and($found->firstWhere('label', 'Acme lamp')['url'])->toBe("/product/{$lamp['product_id']}/edit")
        ->and($found->firstWhere('group', 'Customer')['url'])->toBe("/customer/{$scope['contact_id']}/view")
        ->and($theirs->id)->toBeInt();

    // a page by its name, only when permitted
    expect($this->getJson('/api/search?q=stock')->json('data'))->not->toBe([]);
    Sanctum::actingAs(w1bStaff($scope, ['/product']));
    $labels = collect($this->getJson('/api/search?q=acme')->json('data'))->pluck('label')->all();
    expect($labels)->toContain('Acme lamp')->not->toContain('Acme Retail')->not->toContain('ACME-INV-1');

    // too short, wildcards typed by the user are literal
    $this->getJson('/api/search?q=a')->assertUnprocessable();
    expect($this->getJson('/api/search?q=%25%25')->json('data'))->toBe([]);
});

test('a branch user finds only the documents of their branch and a user without a company sees pages only', function () {
    $scope = fcoScope();
    $second = DB::table('branches')->insertGetId(['code' => 'W1BB2', 'company_id' => $scope['company_id'], 'name' => 'Second', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    w1bSale($scope, ['invoice_no' => 'ZED-MINE']);
    w1bSale($scope, ['invoice_no' => 'ZED-THEIRS', 'branch_id' => $second]);
    $role = Role::query()->create(['name' => 'w1b_branch', 'company_id' => $scope['company_id'], 'is_active' => true]);
    grantMenuPermission($role->id, '/sell');
    $branchUser = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);

    Sanctum::actingAs($branchUser);
    expect(collect($this->getJson('/api/search?q=zed')->json('data'))->pluck('label')->all())->toBe(['ZED-MINE']);

    Sanctum::actingAs(User::query()->findOrFail(1));
    $groups = collect($this->getJson('/api/search?q=sell')->assertOk()->json('data'))->pluck('group')->unique()->all();
    expect($groups === [] || $groups === ['Page'])->toBeTrue();
});

// ---------------------------------------------------------------------------------------------------------------------
// daily business summary

test('the daily summary works out the day, is off until switched on, and sends each recipient once a day', function () {
    $scope = fcoScope();
    $today = now()->toDateString();
    w1bSale($scope, ['invoice_no' => 'DS-1', 'final_amount' => 800]);
    w1bSale($scope, ['invoice_no' => 'DS-2', 'final_amount' => 200]);
    app(AccountsVoucher::class)->post($scope['company_id'], $scope['branch_id'], $today, [['account_id' => $scope['rent_id'], 'debit' => 150], ['account_id' => $scope['cash_id'], 'credit' => 150]], 'Tea and sugar', '', 'EXP');
    $admin = w1bStaff($scope, ['/dashboard/summary']);
    Sanctum::actingAs($admin);

    $figures = $this->getJson('/api/dashboard-tools/summary/preview')->assertOk()->json('data');
    expect($figures['figures']['net_sales'])->toEqual(1000)->and($figures['figures']['invoices'])->toBe(2)->and($figures['figures']['expenses'])->toEqual(150)->and($figures['text'])->toContain('Sales: 1,000.00');

    // off by default: the command sends nothing
    Mail::fake();
    $this->artisan('dashboard:daily-summary')->assertSuccessful();
    expect(DB::table('daily_summary_runs')->count())->toBe(0);

    $this->putJson('/api/dashboard-tools/summary', ['enabled' => true, 'send_hour' => 0, 'channels' => ['email'], 'recipients' => ['owner@example.test']])->assertOk();
    $this->putJson('/api/dashboard-tools/summary', ['enabled' => true, 'recipients' => ['not an address']])->assertUnprocessable();

    $this->artisan('dashboard:daily-summary')->assertSuccessful();
    $this->artisan('dashboard:daily-summary')->assertSuccessful();

    expect(DB::table('daily_summary_runs')->where('status', 'sent')->count())->toBe(1)->and(DB::table('daily_summary_runs')->value('recipient'))->toBe('owner@example.test');
});

test('the daily summary goes to a phone through the company messaging provider and records a failure', function () {
    $scope = fcoScope();
    DB::table('messaging_settings')->insert([
        'company_id' => $scope['company_id'], 'channel' => 'whatsapp', 'provider' => 'webhook', 'is_active' => 1, 'endpoint' => 'https://hooks.example.test/send', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Http::fake(['hooks.example.test/*' => Http::sequence()->push(['ok' => true], 200)->push('down', 500)]);
    $summary = app(DailyBusinessSummary::class);
    $summary->save($scope['company_id'], ['enabled' => true, 'send_hour' => 0, 'channels' => ['whatsapp'], 'recipients' => ['+923001234567']]);

    $first = $summary->send($scope['company_id'], now()->toDateString());
    expect($first)->toBe(['sent' => 1, 'failed' => 0, 'skipped' => 0])->and($summary->send($scope['company_id'], now()->toDateString()))->toBe(['sent' => 0, 'failed' => 0, 'skipped' => 1]);

    $tomorrow = now()->addDay()->toDateString();
    $second = $summary->send($scope['company_id'], $tomorrow);
    expect($second['failed'])->toBe(1)->and(DB::table('daily_summary_runs')->where('run_date', $tomorrow)->value('status'))->toBe('failed');
});

test('the daily summary settings are the company own and need their permission', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    Sanctum::actingAs(w1bStaff($other, ['/dashboard/summary']));
    $this->putJson('/api/dashboard-tools/summary', ['enabled' => true, 'recipients' => ['them@example.test'], 'company_id' => $scope['company_id']])->assertOk();

    Sanctum::actingAs(w1bStaff($scope, ['/dashboard/summary']));
    expect($this->getJson('/api/dashboard-tools/summary')->json('data.recipients'))->toBe([])->and($this->getJson('/api/dashboard-tools/summary')->json('data.enabled'))->toBeFalse();

    Sanctum::actingAs(w1bStaff($scope, ['/dashboard']));
    $this->getJson('/api/dashboard-tools/summary')->assertForbidden();
    $this->putJson('/api/dashboard-tools/summary', ['enabled' => true])->assertForbidden();
});

// ---------------------------------------------------------------------------------------------------------------------
// layouts by role and personal layout

test('a user gets their own layout, else the company layout of their role, else the built-in layout of that kind of role', function () {
    $scope = fcoScope();
    $accountant = w1bStaff($scope, ['/dashboard'], 'Senior Accountant');
    $cashier = w1bStaff($scope, ['/dashboard'], 'Cashier');
    $plain = w1bStaff($scope, ['/dashboard'], 'Some other role');
    $admin = w1bStaff($scope, ['/dashboard/layouts']);

    Sanctum::actingAs($accountant);
    $layout = $this->getJson('/api/dashboard-tools/layout')->assertOk()->json('data');
    expect($layout['source'])->toBe('preset')->and($layout['kind'])->toBe('accountant')->and($layout['order'][0])->toBe('financial')->and($layout['hidden'])->toContain('inventory');

    Sanctum::actingAs($plain);
    expect($this->getJson('/api/dashboard-tools/layout')->json('data.source'))->toBe('default')->and($this->getJson('/api/dashboard-tools/layout')->json('data.hidden'))->toBe([]);

    // the company sets a layout for the cashier role
    Sanctum::actingAs($admin);
    $this->putJson("/api/dashboard-tools/role-layouts/{$cashier->role_id}", ['shown' => ['recent', 'sales']])->assertOk();
    $this->putJson("/api/dashboard-tools/role-layouts/{$cashier->role_id}", ['shown' => ['nonsense']])->assertUnprocessable();

    Sanctum::actingAs($cashier);
    $layout = $this->getJson('/api/dashboard-tools/layout')->json('data');
    expect($layout['source'])->toBe('role')->and(array_slice($layout['order'], 0, 2))->toBe(['recent', 'sales'])->and($layout['hidden'])->toContain('financial')->not->toContain('recent');

    // their own layout wins
    $this->putJson('/api/dashboard-preferences', ['order' => ['stats', 'sales'], 'hidden' => ['recent']])->assertOk();
    expect($this->getJson('/api/dashboard-tools/layout')->json('data.source'))->toBe('personal');

    // another company cannot set a layout for this role
    $other = fcoOtherCompany();
    Sanctum::actingAs(w1bStaff($other, ['/dashboard/layouts']));
    $this->putJson("/api/dashboard-tools/role-layouts/{$cashier->role_id}", ['shown' => ['sales']])->assertNotFound();
    expect(DashboardLayouts::kindFor('Owner'))->toBe('owner');
});

// ---------------------------------------------------------------------------------------------------------------------
// KPI widget builder

test('a user builds KPI widgets from the figures of reports they may open, and they are theirs alone', function () {
    $scope = fcoScope();
    $user = w1bStaff($scope, ['/dashboard', '/report/advance', '/report/withholding-tax']);
    $second = w1bStaff($scope, ['/dashboard', '/report/advance']);
    Sanctum::actingAs($user);

    $catalog = collect($this->getJson('/api/dashboard-tools/kpi-catalog')->assertOk()->json('data'))->pluck('key')->all();
    expect($catalog)->toContain('advance')->toContain('withholding-tax')->not->toContain('profit-loss-comparative');

    $figures = $this->getJson('/api/dashboard-tools/kpi-figures/withholding-tax')->assertOk()->json('data');
    expect($figures)->toContain('receivable')->toContain('payable');
    $this->getJson('/api/dashboard-tools/kpi-figures/tax-liability')->assertForbidden();

    $id = (int) $this->postJson('/api/dashboard-tools/kpis', ['report_key' => 'withholding-tax', 'figure_key' => 'payable', 'period' => 'month', 'label' => 'Tax we owe'])->assertCreated()->json('data.id');
    $this->postJson('/api/dashboard-tools/kpis', ['report_key' => 'withholding-tax', 'figure_key' => 'nonsense', 'period' => 'month'])->assertUnprocessable();
    $this->postJson('/api/dashboard-tools/kpis', ['report_key' => 'tax-liability', 'figure_key' => 'net_tax', 'period' => 'month'])->assertForbidden();
    $this->postJson('/api/dashboard-tools/kpis', ['report_key' => 'withholding-tax', 'figure_key' => 'payable', 'period' => 'decade'])->assertUnprocessable();

    $list = $this->getJson('/api/dashboard-tools/kpis')->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['label'])->toBe('Tax we owe')->and($list[0]['value'])->toEqual(0);

    // another user neither sees nor deletes it
    Sanctum::actingAs($second);
    expect($this->getJson('/api/dashboard-tools/kpis')->json('data'))->toBe([]);
    $this->deleteJson("/api/dashboard-tools/kpis/{$id}")->assertNotFound();

    // losing the report permission blanks the value instead of leaking it
    DB::table('permissions')->where('role_id', $user->role_id)->delete();
    Sanctum::actingAs($user->fresh());
    cache()->flush();
    expect($this->getJson('/api/dashboard-tools/kpis')->json('data.0.value'))->toBeNull();
});

// ---------------------------------------------------------------------------------------------------------------------
// date range and warehouse filters

test('the sales card takes a date range and a warehouse, and without them answers exactly as before', function () {
    $scope = fcoScope();
    $warehouse = DB::table('warehouses')->insertGetId(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'name' => 'Main WH', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('transactions')->where('id', w1bSale($scope, ['invoice_no' => 'R-1', 'final_amount' => 300, 'transaction_date' => now()->subDays(10)->toDateString()]))->update(['warehouse_id' => $warehouse]);
    w1bSale($scope, ['invoice_no' => 'R-2', 'final_amount' => 700, 'transaction_date' => now()->subDays(2)->toDateString()]);
    Sanctum::actingAs(w1bStaff($scope, ['/dashboard', '/sell', '/purchase']));

    $plain = $this->getJson('/api/dashboard/sales')->assertOk()->json('data');
    expect($plain)->not->toHaveKey('range');

    $from = now()->subDays(12)->toDateString();
    $to = now()->toDateString();
    $ranged = $this->getJson("/api/dashboard/sales?start_date={$from}&end_date={$to}")->assertOk()->json('data');
    expect($ranged['range']['net_sales'])->toEqual(1000)->and($ranged['range']['from'])->toBe($from)->and($ranged['sales']['this_month'])->toEqual($plain['sales']['this_month']);

    $byWarehouse = $this->getJson("/api/dashboard/sales?start_date={$from}&end_date={$to}&warehouse_id={$warehouse}")->assertOk()->json('data');
    expect($byWarehouse['range']['net_sales'])->toEqual(300)->and($byWarehouse['range']['warehouse_id'])->toBe($warehouse);

    // a warehouse of another company is ignored as a filter and shows nothing of ours
    $other = fcoOtherCompany();
    $foreign = DB::table('warehouses')->insertGetId(['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'name' => 'Foreign', 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson("/api/dashboard/sales?start_date={$from}&end_date={$to}&warehouse_id={$foreign}")->assertUnprocessable();
});
