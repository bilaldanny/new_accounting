<?php

use App\Models\Role;
use App\Models\User;
use App\Services\System\DemoHistoryCompany;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * Not part of the normal run: takes real screenshots of the application's pages in a phone-sized viewport (390x844 at 2x) for the social media campaign.
 * The data is the DEMO-HISTORY company (twelve months of fictional trade). Run it on purpose with CAPTURE_MOBILE_SCREENSHOTS=1 and
 * the inline sqlite environment of the other Dusk runs (storage/dusk-run.sh); the pictures land in tests/Browser/screenshots/mob-*.png
 * and are used by generate_device_mockups.py.
 */
test('capture real mobile screenshots of the application pages', function () {
    if (getenv('CAPTURE_MOBILE_SCREENSHOTS') !== '1') {
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
        '01-dashboard' => '/dashboard', '02-sales' => '/sell', '03-pos' => '/sell/pos/add', '04-customers' => '/customer', '05-products' => '/product',
        '06-profit-loss' => '/report/profit-loss', '07-balance-sheet' => '/report/balance-sheet', '08-stock-report' => '/report/stock', '09-expenses' => '/expense',
        '10-add-sale' => '/sell/add', '11-trial-balance' => '/report/trial-balance', '12-cash-flow' => '/report/cash-flow',
    ];



    $this->browse(function (Browser $browser) use ($shots, $admin) {
        $this->signIn($browser, $admin)->resize(1000, 1000);
        $browser->driver->executeCustomCommand('/session/'.$browser->driver->getSessionID().'/goog/cdp/execute', 'POST', ['cmd' => 'Emulation.setDeviceMetricsOverride', 'params' => ['width' => 390, 'height' => 844, 'deviceScaleFactor' => 2, 'mobile' => true]]);
        $browser->driver->executeScript("localStorage.setItem('app-tour-seen:{$admin->id}', '1');");

        foreach ($shots as $name => $url) {
            try {
                $browser->visit($url)->pause(9000);
                $settled = fn (): bool => ! preg_match('/Loading|Checking|Preparing/', (string) $browser->driver->executeScript('return document.body.innerText;'));
                $browser->waitUsing(90, 1, $settled);
                $browser->pause(3000)->screenshot('mob-'.$name);
            } catch (Throwable $e) {
                fwrite(STDERR, "skipped {$name}: {$e->getMessage()}\n");
            }
        }
    });

    expect(true)->toBeTrue();
});
