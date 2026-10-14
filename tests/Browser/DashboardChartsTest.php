<?php

use Laravel\Dusk\Browser;

/**
 * The dashboard graphs and the attention list in a real browser, over a company with a few sales: the charts are drawn as SVG with the
 * sales bars, the glance card lists quick actions, and nothing logs a browser error.
 */
test('the dashboard draws its graphs, the attention list and the quick actions', function () {
    $world = $this->world();

    // the trend graphs are drawn for a user who may open the purchase and sale report
    foreach (['/report/purchase-sale', '/report/profit-loss', '/report/customer-outstanding', '/report/supplier-outstanding', '/report/stock'] as $path) {
        grantMenuPermission((int) $world['role_id'], $path, ltrim($path, '/').uniqid());
    }

    foreach ([60, 35, 20, 8, 2] as $daysAgo) {
        createSellRecord($world, ['invoice_no' => 'DC-'.$daysAgo.'-'.random_int(100, 999), 'final_amount' => 1000 + $daysAgo * 37, 'transaction_date' => now()->subDays($daysAgo)->toDateString(), 'status' => 'final']);
    }

    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())->visit('/dashboard')->waitFor('[data-test="dashboard-glance"]', 40);

        $browser->waitFor('[data-test="dashboard-charts"]', 30)->assertSee('Sales and purchases')->assertSee('Quick actions')->assertSee('Needs your attention');
        $browser->waitUsing(30, 1, fn (): bool => (int) $browser->driver->executeScript('return document.querySelectorAll("[data-test=dashboard-charts] svg rect").length;') > 0);
        $browser->script('document.querySelector("[data-test=dashboard-charts]").scrollIntoView({block: "start"});');
        $browser->pause(400)->screenshot('dashboard-charts');

        $this->assertNoBrowserErrors($browser);
    });
});
