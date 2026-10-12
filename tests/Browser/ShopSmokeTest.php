<?php

use Laravel\Dusk\Browser;

/**
 * A real-browser smoke check of the online shop: staff open the shop in settings, the public address is shown, and the public shop
 * page opens for anyone with its cart and order form. Screenshots in tests/Browser/screenshots.
 */
test('the shop is opened in settings and its public page loads with the order form', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())
            ->visit('/storefront')
            ->waitForText('Shop settings', 30)
            ->waitFor('[aria-label="Shop code"]', 20)
            ->type('[aria-label="Shop code"]', 'dusk-shop')
            ->type('[aria-label="Shop title"]', 'Dusk Shop')
            ->check('#shop-on')
            ->press('Save')
            ->waitForText('Public address', 20)
            ->screenshot('wave8-storefront-admin')
            ->visit('/shop/dusk-shop')
            ->waitForText('Dusk Shop', 30)
            ->waitForText('Your order', 20)
            ->assertPresent('[aria-label="Phone number"]')
            ->assertSee('Send order')
            ->screenshot('wave8-storefront-public');

        $this->assertNoBrowserErrors($browser);
    });
});
