<?php

use App\Models\Role;
use App\Models\User;
use App\Services\System\DemoHistoryCompany;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * Not part of the normal run: takes real screenshots of the application's pages (light theme, or dark with CAPTURE_THEME=dark) for the social media campaign.
 * The data is the DEMO-HISTORY company (twelve months of fictional trade). Run it on purpose with CAPTURE_MARKETING_SCREENSHOTS=1 and
 * the inline sqlite environment of the other Dusk runs (storage/dusk-run.sh); the pictures land in tests/Browser/screenshots/mk-*.png
 * and are scaled to 1200x630 into screenshots/ by generate_real_screenshots.py.
 */
test('capture real screenshots of the application pages', function () {
    if (getenv('CAPTURE_MARKETING_SCREENSHOTS') !== '1') {
        $this->markTestSkipped('Only runs on request.');
    }

    $this->world();
    DB::table('settings')->updateOrInsert(['id' => 1], ['name' => 'Xelora Systems', 'system_logo' => '/storage/photos/1/logo_icon.png', 'created_at' => now(), 'updated_at' => now()]);

    $result = app(DemoHistoryCompany::class)->build(12, 24, 6);
    $admin = User::query()->where('email', $result['email'])->firstOrFail();

    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $rows = json_decode((string) file_get_contents(base_path('tests/Fixtures/live-menus.json')), true);
    foreach ($rows as $row) {
        DB::table('menus')->insert(array_merge($row, ['route_path' => $row['route_path'] ?? '', 'route_name' => $row['route_name'] ?? '', 'parent_id' => null, 'menu_color' => '#199683', 'icon' => '', 'created_at' => now(), 'updated_at' => now()]));
    }
    foreach ($rows as $row) {
        DB::table('menus')->where('id', $row['id'])->update(['parent_id' => $row['parent_id']]);
    }
    $role = Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true]);
    foreach (DB::table('menus')->where(fn ($q) => $q->where('route_path', 'not like', '/blogposts%')->where('route_path', 'not like', '/subscription%')->where('route_path', 'not like', '/tenants%')->where('route_path', 'not like', '/coupons%')->where('route_path', 'not like', '/webhooks%')->where('route_path', 'not like', '/company%')->where('route_path', 'not like', '/menu%')->where('name', '!=', 'Website'))->pluck('id') as $menuId) {
        DB::table('permissions')->insert(['role_id' => $role->id, 'menu_id' => $menuId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    $admin->forceFill(['role_id' => $role->id, 'first_name' => 'Bilal', 'last_name' => 'Younus'])->save();
    foreach (['user_permission_paths', 'user_menu_permissions', 'user_menu_permissions_tree'] as $key) {
        cache()->forget("{$key}:{$role->id}");
    }

    $shots = [
        '01-dashboard' => '/dashboard', '02-sales' => '/sell', '03-products' => '/product', '04-purchases' => '/purchase', '05-customers' => '/customer',
        '06-suppliers' => '/supplier', '07-profit-loss' => '/report/profit-loss', '08-balance-sheet' => '/report/balance-sheet', '09-trial-balance' => '/report/trial-balance',
        '10-cash-flow' => '/report/cash-flow', '11-stock-report' => '/report/stock', '12-journal-entries' => '/journalentry', '13-expenses' => '/expense',
        '14-bank-reconciliation' => '/bankreconciliation', '15-chart-of-accounts' => '/chart-of-account', '16-tax-report' => '/report/tax', '17-sell-report' => '/report/sell',
        '18-customer-outstanding' => '/report/customer-outstanding', '19-supplier-outstanding' => '/report/supplier-outstanding', '20-warehouse-stock' => '/report/warehouse-stock',
        '21-pos' => '/sell/pos/add', '22-audit-log' => '/auditlogs', '23-roles' => '/role', '24-purchase-payments' => '/purchase/payment', '25-sell-payments' => '/sell/payment',
        '26-cash-flow-forecast' => '/cashflowforecast', '27-financial-ratios' => '/report/financial-ratios', '28-stock-ageing' => '/report/stock-ageing',
        '29-profit-by-customer' => '/report/profit-by-customer', '30-sales-by-payment-method' => '/report/sales-by-payment-method', '31-account-ledger' => '/report/account-ledger',
        '32-add-sale' => '/sell/add', '33-add-purchase' => '/purchase/add', '34-product-add' => '/product/add',
    ];


    $this->browse(function (Browser $browser) use ($shots, $admin) {
        $this->signIn($browser, $admin)->resize(1600, 982);
        $browser->driver->executeScript("localStorage.setItem('app-tour-seen:{$admin->id}', '1');");

        foreach ($shots as $name => $url) {
            try {
                $browser->visit($url)->pause(2500);
                if (getenv('CAPTURE_THEME') === 'dark') {
                    $browser->driver->executeScript("document.documentElement.classList.add('dark-theme');");
                }
                $browser->waitUsing(60, 1, fn (): bool => ! str_contains((string) $browser->driver->executeScript('return document.body.innerText;'), 'Loading...'));
                $browser->pause(1200)->screenshot('mk-'.$name);
            } catch (Throwable $e) {
                fwrite(STDERR, "skipped {$name}: {$e->getMessage()}\n");
            }
        }
    });

    expect(true)->toBeTrue();
});
