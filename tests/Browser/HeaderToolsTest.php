<?php

use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * The top bar tools in a real browser: the date, Today Profit, the calculator (mouse and keyboard), the calendar, the to-do list with
 * its count, and the application tour.
 */
test('the header shows the date, Today Profit, a working calculator, the calendar, to-dos and the tour', function () {
    $admin = $this->adminUser();
    $menu = DB::table('menus')->where('route_path', '/report/profit-loss')->value('id')
        ?? DB::table('menus')->insertGetId(['parent_id' => null, 'name' => 'Profit and Loss', 'icon' => 'Grid', 'route_name' => 'report.profit-loss', 'route_path' => '/report/profit-loss', 'menu_color' => '#000', 'sort_order' => 900, 'is_hidden' => 1, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'is_admin' => 0, 'created_at' => now(), 'updated_at' => now()]);

    if (! DB::table('permissions')->where('role_id', $admin->role_id)->where('menu_id', $menu)->exists()) {
        DB::table('permissions')->insert(['company_id' => null, 'branch_id' => null, 'department_id' => null, 'role_id' => $admin->role_id, 'menu_id' => $menu, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::forget("{$key}:{$admin->role_id}");
    }

    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())->visit('/dashboard')->waitFor('[data-test="header-tools"]', 40);
        $browser->waitUsing(15, 1, fn (): bool => trim((string) $browser->driver->executeScript('return document.querySelector("[data-test=header-date]").textContent;')) !== '');

        // Today Profit opens a table with the figures
        $browser->click('[data-test="today-profit-button"]')->waitFor('[data-test="today-profit"]', 20)->assertSee('Net profit')->assertSee('Gross profit');
        $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);
        $browser->waitUntilMissing('[data-test="today-profit"]', 10);

        // calculator: 12 + 8 = 20, then 200 + 10 % = 220
        $browser->click('[data-test="calculator-button"]')->waitFor('[data-test="calculator"]', 10);
        $browser->driver->getKeyboard()->sendKeys('12+8'.WebDriverKeys::ENTER);
        $browser->waitUsing(10, 1, fn (): bool => trim((string) $browser->driver->executeScript('return document.querySelector("[data-test=calc-display]").textContent;')) === '20');
        $browser->driver->getKeyboard()->sendKeys('c200+10%='.'');
        $browser->waitUsing(10, 1, fn (): bool => trim((string) $browser->driver->executeScript('return document.querySelector("[data-test=calc-display]").textContent;')) === '220');
        $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);
        $browser->waitUntilMissing('[data-test="calculator"]', 10);

        // to-do: add one, the count shows in the header
        $browser->click('[data-test="header-more"]')->click('[data-test="open-todo"]')->waitFor('[data-test="todo-input"]', 10);
        $browser->type('[data-test="todo-input"]', 'Call the supplier')->click('[data-test="todo-add"]');
        $browser->waitForText('Call the supplier', 10);
        $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);
        $browser->waitUntilMissing('[data-test="todo-input"]', 10);
        $browser->waitFor('[data-test="todo-count"]', 10);

        // calendar
        $browser->click('[data-test="header-more"]')->click('[data-test="open-calendar"]')->waitFor('[data-test="calendar"]', 10);
        $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);
        $browser->waitUntilMissing('[data-test="calendar"]', 10);

        // tour: next through the steps, then done
        $browser->click('[data-test="header-more"]')->click('[data-test="start-tour"]')->waitFor('[data-test="tour"]', 10);
        for ($i = 0; $i < 8 && $browser->element('[data-test="tour"]') !== null; $i++) {
            $browser->click('[data-test="tour-next"]');
            usleep(400000);
        }

        $browser->waitUntilMissing('[data-test="tour"]', 10);
        $this->assertNoBrowserErrors($browser);
    });
});
