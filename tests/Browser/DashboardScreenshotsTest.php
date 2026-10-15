<?php

use App\Models\User;
use App\Services\System\DemoHistoryCompany;
use Laravel\Dusk\Browser;

/**
 * Not part of the normal run: builds the DEMO-HISTORY company (twelve months of seasonal trade) in the Dusk database and takes the
 * dashboard in the light and the dark theme. Run it on purpose with CAPTURE_DASHBOARD_SCREENSHOTS=1 and the inline sqlite environment of
 * the other Dusk runs; the pictures land in tests/Browser/screenshots and are copied to docs/dashboard-screens by hand.
 */
test('capture the dashboard of the history demo company in light and dark', function () {
    if (getenv('CAPTURE_DASHBOARD_SCREENSHOTS') !== '1') {
        $this->markTestSkipped('Only runs on request.');
    }

    $this->world();
    $result = app(DemoHistoryCompany::class)->build(12, 20, 5);
    $admin = User::query()->where('email', $result['email'])->firstOrFail();

    $this->browse(function (Browser $browser) use ($admin) {
        $this->signIn($browser, $admin)->resize(1600, 2400)->visit('/dashboard')->waitFor('[data-test="dashboard-extras"]', 60);
        $browser->driver->executeScript("localStorage.setItem('app-tour-seen:{$admin->id}', '1');");
        $browser->refresh()->waitFor('[data-test="dashboard-extras"]', 60);
        $settled = fn () => $browser->waitUsing(90, 1, fn (): bool => ! str_contains((string) $browser->driver->executeScript('return document.body.innerText;'), 'Loading...') && ! str_contains((string) $browser->driver->executeScript('return document.body.innerText;'), 'Checking...'));
        $settled();
        $browser->pause(800)->screenshot('dashboard-light');

        // the template's dark theme is the `dark-theme` class on the html element
        $browser->driver->executeScript("document.documentElement.classList.add('dark-theme');");
        $browser->pause(500)->screenshot('dashboard-dark');
    });
});
