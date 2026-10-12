<?php

use Laravel\Dusk\Browser;

/**
 * A real-browser smoke check of the report tools: the builder runs a report and shows its rows, the saved-filter bar is on a report,
 * and the analytics page renders its blocks. Screenshots are kept in tests/Browser/screenshots.
 */
test('the report builder previews a report in the browser', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())
            ->visit('/reportbuilder')
            ->waitForText('Report Builder', 30)
            ->waitFor('#rb-name', 20)
            ->type('#rb-name', 'Smoke report')
            ->waitFor('#rb-col-invoice_no', 20)
            ->check('#rb-col-invoice_no')
            ->check('#rb-col-final_amount')
            ->press('Preview')
            ->waitForText('Invoice No', 20)
            ->assertSee('Result')
            ->screenshot('wave4-report-builder');

        $this->assertNoBrowserErrors($browser);
    });
});

test('a report page shows the saved filter bar and the comparison fields', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())
            ->visit('/report/profit-loss-comparative')
            ->waitForText('Comparative Profit & Loss', 30)
            ->waitFor('.report-saved-filters', 20)
            ->assertPresent('.report-saved-filters')
            ->screenshot('wave4-comparative-profit-loss');

        $this->assertNoBrowserErrors($browser);
    });
});

test('the analytics page renders its blocks', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())
            ->visit('/analytics')
            ->waitForText('Net sales, last six months', 30)
            ->assertSee('Forecast for')
            ->assertSee('Profit by branch')
            ->screenshot('wave4-analytics');

        $this->assertNoBrowserErrors($browser);
    });
});
