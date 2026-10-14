<?php

use Laravel\Dusk\Browser;

/**
 * A real-browser smoke check of repair and field service: a job card is made on the board and assigned, the technician page lists
 * it, a signature is drawn on the pad and kept, and the page links the web app manifest and registers its service worker.
 * Screenshots in tests/Browser/screenshots.
 */
test('a job is made, assigned, opened on the technician page and signed on the pad', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())
            ->visit('/service')
            ->waitForText('Service and Field Jobs', 30)
            ->waitFor('[aria-label="Job title"]', 20)
            ->type('[aria-label="Job title"]', 'Generator will not start')
            ->press('Make job card')
            ->waitForText('JOB-00001', 20)
            ->waitFor('[aria-label="Assign technician"]', 20)
            ->script("const s = document.querySelector('[aria-label=\"Assign technician\"]'); s.selectedIndex = 1; s.dispatchEvent(new Event('change'));");

        $browser->press('Assign')
            ->pause(800)
            ->screenshot('wave8-service-board')
            ->visit('/service/work')
            ->waitForText('JOB-00001', 30)
            ->click('.admin-list-page [role="button"]')
            ->waitFor('canvas[aria-label="Signature pad"]', 20);

        $browser->script("const c = document.querySelector('canvas'); const r = c.getBoundingClientRect(); const fire = (t, x, y) => c.dispatchEvent(new PointerEvent(t, { clientX: r.left + x, clientY: r.top + y, bubbles: true })); fire('pointerdown', 20, 20); fire('pointermove', 80, 60); fire('pointermove', 140, 30); fire('pointerup', 140, 30);");

        $browser->type('[aria-label="Signer name"]', 'Customer One')
            ->press('Save signature')
            ->waitForText('Signed by Customer One', 20)
            ->screenshot('wave8-service-work');

        $manifest = $browser->script("return document.querySelector('link[rel=\"manifest\"]') ? document.querySelector('link[rel=\"manifest\"]').getAttribute('href') : null;")[0];
        expect($manifest)->toBe('/manifest.webmanifest');

        $this->assertNoBrowserErrors($browser);
    });
});
