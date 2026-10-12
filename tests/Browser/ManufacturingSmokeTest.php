<?php

use Laravel\Dusk\Browser;

/**
 * A real-browser smoke check of Manufacturing: the page opens on work orders, the bills of materials tab offers the draft form with
 * its component, operation and by-product rows, and the settings tab explains that posting is off until the accounts are mapped.
 * Screenshots in tests/Browser/screenshots.
 */
test('the manufacturing page offers work orders, the BOM form and the posting settings', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())
            ->visit('/manufacturing')
            ->waitForText('Manufacturing', 30)
            ->waitForText('No work orders yet.', 20)
            ->press('Bills of materials')
            ->waitForText('New bill of materials', 20)
            ->press('Add component')
            ->press('Add operation')
            ->press('Add by-product')
            ->assertPresent('[aria-label="Component"]')
            ->assertPresent('[aria-label="Operation name"]')
            ->screenshot('wave8-manufacturing-bom')
            ->press('Settings')
            ->waitForText('Post manufacturing to the ledger', 20)
            ->assertSee('only when you switch posting on')
            ->screenshot('wave8-manufacturing-settings');

        $this->assertNoBrowserErrors($browser);
    });
});
