<?php

use Laravel\Dusk\Browser;

/**
 * A real-browser smoke check of the Document Center: a design is started from the built-in blocks and previewed with sample data
 * in a sandboxed frame. The screenshot is kept in tests/Browser/screenshots.
 */
test('the document designer previews a template with sample data', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())
            ->visit('/documentcenter')
            ->waitForText('Document Center', 30)
            ->waitForText('New from built-in design', 20)
            ->press('New from built-in design')
            ->waitFor('#dc-name', 20)
            ->type('#dc-name', 'Smoke invoice')
            ->scrollIntoView('#dc-preview')
            ->click('#dc-preview')
            ->waitFor('iframe[title="Document preview"]', 20)
            ->assertPresent('iframe[title="Document preview"]')
            ->screenshot('wave5-document-designer');

        $this->assertNoBrowserErrors($browser);
    });
});
