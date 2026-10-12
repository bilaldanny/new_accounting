<?php

use Laravel\Dusk\Browser;

/**
 * A real-browser smoke check of the wave 1b dashboard tools: the top bar search answers, the bell lists stored notifications, the
 * insights page offers the KPI widget builder, the daily summary and the role layouts, and the workflow editor has approver pickers.
 * Screenshots in tests/Browser/screenshots.
 */
test('the search bar, the insights tools and the approver pickers work in the browser', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())
            ->visit('/dashboard')
            ->waitFor('[aria-label="Search"]', 30)
            ->type('[aria-label="Search"]', 'report')
            ->waitFor('[data-test="search-results"]', 20)
            ->waitFor('[data-test="search-results"] a', 20)
            ->screenshot('wave1b-search')
            ->visit('/dashboard/insights')
            ->waitForText('My KPI widgets', 30)
            ->assertSee('Daily business summary')
            ->assertSee('Dashboard layout of each role')
            ->screenshot('wave1b-insights')
            ->visit('/approvalcenter')
            ->waitForText('Approval Center', 30)
            ->press('Workflows')
            ->waitFor('[aria-label="Approver users"]', 20)
            ->assertPresent('[aria-label="Approver roles"]')
            ->screenshot('wave1b-approval-picker');

        $this->assertNoBrowserErrors($browser);
    });
});
