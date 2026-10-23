<?php

use App\Models\Role;
use App\Models\User;
use App\Services\System\DemoHistoryCompany;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * Not part of the normal run: it takes the screenshots shown on the landing page. The data is the DEMO-HISTORY company (twelve months
 * of fictional trade posted through the application's own code), so the dashboard, lists and reports carry real, consistent figures.
 * Run it on purpose with CAPTURE_LANDING_SCREENSHOTS=1 (and the inline sqlite environment of the other Dusk runs, storage/dusk-run.sh);
 * the pictures land in tests/Browser/screenshots/landing-*.png and are turned into public/assets/landing/*.jpg by hand
 * (tests/Browser/landing-shots.ps1).
 */
test('capture the screenshots for the landing page', function () {
    if (getenv('CAPTURE_LANDING_SCREENSHOTS') !== '1') {
        $this->markTestSkipped('Only runs on request.');
    }

    $this->world();
    DB::table('settings')->updateOrInsert(['id' => 1], ['name' => 'ACCUNIVO', 'system_logo' => '/storage/photos/1/logo_icon.png', 'created_at' => now(), 'updated_at' => now()]);

    $result = app(DemoHistoryCompany::class)->build(12, 24, 6);
    $admin = User::query()->where('email', $result['email'])->firstOrFail();

    // The real menu tree (proper names, no permission-only rows) and a company admin who has all of it, like a live admin.
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
    $admin->forceFill(['role_id' => $role->id, 'first_name' => 'Amina', 'last_name' => 'Rahman'])->save();
    foreach (['user_permission_paths', 'user_menu_permissions', 'user_menu_permissions_tree'] as $key) {
        cache()->forget("{$key}:{$role->id}");
    }

    $shots = [
        'dashboard' => '/dashboard',
        'sales' => '/sell',
        'products' => '/product',
        'sellreport' => '/report/sell',
    ];

    $this->browse(function (Browser $browser) use ($shots, $admin) {
        $this->signIn($browser, $admin)->resize(1800, 1100);
        $browser->driver->executeScript("localStorage.setItem('app-tour-seen:{$admin->id}', '1');");

        foreach ($shots as $name => $url) {
            $browser->visit($url)->pause(3000);
            $browser->waitUsing(90, 1, fn (): bool => ! str_contains((string) $browser->driver->executeScript('return document.body.innerText;'), 'Loading...'));
            $browser->pause(1500)->screenshot('landing-'.$name);
        }
    });

    expect(true)->toBeTrue();
});
