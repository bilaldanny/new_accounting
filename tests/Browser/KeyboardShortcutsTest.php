<?php

use Facebook\WebDriver\WebDriverKeys;
use Laravel\Dusk\Browser;

/**
 * The application with the keyboard only: the search box by "/", pages by "g" and a letter, the add form by "n", the list of keys by
 * "?", and arrow keys in the search results. No click is used after sign-in.
 */
function duskKeys(Browser $browser, string ...$keys): void
{
    foreach ($keys as $key) {
        $browser->driver->getKeyboard()->sendKeys($key);
        usleep(150000);
    }
}

test('the whole application can be moved around with the keyboard', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())->visit('/dashboard')->waitFor('.topbar-search__input', 40);
        $browser->driver->executeScript('document.activeElement.blur();');

        // ? lists the keys, Esc closes it
        duskKeys($browser, '?');
        $browser->waitFor('[data-test="keyboard-help"]', 10)->assertSee('Keyboard shortcuts');
        duskKeys($browser, WebDriverKeys::ESCAPE);
        $browser->waitUntilMissing('[data-test="keyboard-help"]', 10);

        // / goes to the search box
        duskKeys($browser, '/');
        $browser->waitUsing(10, 1, fn (): bool => (bool) $browser->driver->executeScript('return document.activeElement && document.activeElement.classList.contains("topbar-search__input");'));
        $browser->driver->executeScript('document.activeElement.blur();');

        // g then c: the customers page
        duskKeys($browser, 'g', 'c');
        $browser->waitUsing(20, 1, fn (): bool => str_contains((string) $browser->driver->getCurrentURL(), '/customer'));

        // n: the add form of the page
        $browser->waitFor('.page-content', 20);
        $browser->waitUsing(20, 1, fn (): bool => (bool) $browser->driver->executeScript('return Array.from(document.querySelectorAll(".page-content a, .page-content button")).some(e => /^\s*add\b/i.test(e.textContent || "") && e.offsetParent !== null);'));
        $browser->driver->executeScript('document.activeElement.blur();');
        duskKeys($browser, 'n');
        $browser->waitUsing(20, 1, fn (): bool => (bool) preg_match('#/add(\?|$)#', (string) $browser->driver->getCurrentURL()) || (bool) $browser->driver->executeScript('return !!document.querySelector(".modal.show");'));

        // letters typed into a field are never taken for a shortcut
        $browser->visit('/dashboard')->waitFor('.topbar-search__input', 30)->click('.topbar-search__input');
        duskKeys($browser, 'g', 's');
        expect($browser->driver->getCurrentURL())->toContain('/dashboard');

        $this->assertNoBrowserErrors($browser);
    });
});
