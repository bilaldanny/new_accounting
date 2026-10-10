<?php

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\PeriodLock;
use Laravel\Dusk\Browser;

/**
 * The period lock seen from a browser: the card on the Financial Year tab locks the books, a save into the locked range is refused
 * with the lock's own message, and an administrator sends that one save through with a reason that lands in the audit log.
 */
test('an admin locks the books from the Financial Year tab, a locked save is refused, and one override goes through audited', function () {
    $world = $this->world();
    $payload = validSellPayload($world, ['transaction_date' => '2026-08-12']);

    $this->browse(function (Browser $browser) use ($payload) {
        $this->signIn($browser, $this->adminUser())->visit('/financialyear')->waitForText('Period lock', 30);

        $browser->waitFor('#period-lock-date', 20)->keys('#period-lock-date', '08312026')->type('#period-lock-reason', 'August closed and reviewed');
        duskClickText($browser, 'Lock period');
        $browser->waitForText('Books are locked through', 20)->assertSee('2026-08-31')->assertSee('August closed and reviewed');

        $refused = duskRequest($browser, 'POST', '/api/sells', $payload);
        expect($refused['status'])->toBe(422)
            ->and($refused['body']['period_locked'])->toBeTrue()
            ->and($refused['body']['locked_through'])->toBe('2026-08-31')
            ->and($refused['body']['can_override'])->toBeTrue();

        // The refusal above is expected: drop it from the console log so only unexpected errors are left to assert on.
        $browser->driver->manage()->getLog('browser');

        $overridden = duskRequest($browser, 'POST', '/api/sells', $payload, [PeriodLock::OVERRIDE_HEADER => 'Customer invoice missed before closing']);
        expect($overridden['status'])->toBe(200);

        $browser->visit('/financialyear')->waitForText('Books are locked through', 20)->assertSee('2026-08-31');
        $this->assertNoBrowserErrors($browser);
    });

    expect(Transaction::query()->sells()->whereDate('transaction_date', '2026-08-12')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'period_override')->first()?->new_values['reason'])->toBe('Customer invoice missed before closing');
});
