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

test('a block of the designer is moved by dragging its handle', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())
            ->visit('/documentcenter')
            ->waitForText('Document Center', 30)
            ->waitForText('New from built-in design', 20)
            ->press('New from built-in design')
            ->waitFor('[data-block-index="2"]', 20);

        $names = "return Array.from(document.querySelectorAll('[data-block-index] strong')).map((e) => e.textContent.trim());";
        $before = $browser->script($names)[0];

        $browser->script("const handles = document.querySelectorAll('[draggable=\"true\"]'); const data = new DataTransfer(); handles[2].dispatchEvent(new DragEvent('dragstart', { dataTransfer: data, bubbles: true })); const target = document.querySelector('[data-block-index=\"0\"]'); target.dispatchEvent(new DragEvent('dragover', { dataTransfer: data, bubbles: true, cancelable: true })); target.dispatchEvent(new DragEvent('drop', { dataTransfer: data, bubbles: true, cancelable: true }));");
        $browser->pause(300);
        $after = $browser->script($names)[0];

        expect($after[0])->toBe($before[2])->and(count($after))->toBe(count($before))->and($after)->not->toBe($before);
        $browser->screenshot('wave1b-designer-drag');
        $this->assertNoBrowserErrors($browser);
    });
});
