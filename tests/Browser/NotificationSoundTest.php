<?php

use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * The speaker switch in the top bar: it saves on the account, is still off after a reload and after the browser's own copy is
 * cleared, makes the notification sounds silent, and does not touch another user.
 */
function soundPlays(Browser $browser): int
{
    return (int) $browser->driver->executeScript('return window.__plays || 0;');
}

test('the notification sound switch is saved, remembered and silences the alerts', function () {
    $this->world();
    $admin = $this->adminUser();
    DB::table('users')->where('id', $admin->id)->update(['notification_sound' => true]);

    $this->browse(function (Browser $browser) use ($admin) {
        $this->signIn($browser, $admin)->visit('/dashboard')->waitFor('[data-test="sound-toggle"]', 40);

        // count every sound the page tries to play
        $browser->driver->executeScript('window.__plays = 0; HTMLMediaElement.prototype.play = function () { window.__plays++; return Promise.resolve(); };');
        $browser->assertAttribute('[data-test="sound-toggle"]', 'aria-pressed', 'true');

        // off: the switch is saved on the account
        $browser->click('[data-test="sound-toggle"]');
        $browser->waitUsing(10, 1, fn (): bool => ! (bool) DB::table('users')->where('id', $admin->id)->value('notification_sound'));
        $browser->assertAttribute('[data-test="sound-toggle"]', 'aria-pressed', 'false');

        // after a reload, and even with the browser's own copy gone, it is still off
        $browser->driver->executeScript('localStorage.removeItem("notification-sound");');
        $browser->refresh()->waitFor('[data-test="sound-toggle"]', 40);
        $browser->assertAttribute('[data-test="sound-toggle"]', 'aria-pressed', 'false');

        // off: an alert plays nothing (a page that raises an error message goes through the same code)
        $browser->driver->executeScript('window.__plays = 0; HTMLMediaElement.prototype.play = function () { window.__plays++; return Promise.resolve(); };');
        $browser->visit('/sell/pos/add')->waitFor('[data-test="pos-search"]', 40);
        $browser->driver->executeScript('window.__plays = 0; HTMLMediaElement.prototype.play = function () { window.__plays++; return Promise.resolve(); };');
        $browser->type('[data-test="pos-search"]', '0000000000000')->keys('[data-test="pos-search"]', '{enter}');
        $browser->waitForTextIn('[data-test="pos-scan-status"]', 'No product has the code', 20);
        expect(soundPlays($browser))->toBe(0);

        // back on, and an alert is heard again
        $browser->visit('/dashboard')->waitFor('[data-test="sound-toggle"]', 40)->click('[data-test="sound-toggle"]');
        $browser->waitUsing(10, 1, fn (): bool => (bool) DB::table('users')->where('id', $admin->id)->value('notification_sound'));
        $browser->visit('/sell/pos/add')->waitFor('[data-test="pos-search"]', 40);
        $browser->driver->executeScript('window.__plays = 0; HTMLMediaElement.prototype.play = function () { window.__plays++; return Promise.resolve(); };');
        $browser->type('[data-test="pos-search"]', '0000000000001')->keys('[data-test="pos-search"]', '{enter}');
        $browser->waitUsing(20, 1, fn (): bool => soundPlays($browser) >= 1);

        $this->assertNoBrowserErrors($browser);
    });

    // another user of the same company is untouched
    $other = fcoStaff(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id], ['/dashboard'], false);
    expect((bool) DB::table('users')->where('id', $other->id)->value('notification_sound'))->toBeTrue();
});
