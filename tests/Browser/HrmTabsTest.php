<?php

use Laravel\Dusk\Browser;

/**
 * The HR hub opens on the tab named by its address, so the sidebar entries (Employees, Attendance and Leave, Payroll, HR Setup) each
 * land on their own tab, and clicking a tab moves the address with it.
 */
test('the HR hub opens on the tab of its address and a tab click changes the address', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())->visit('/hrm/payroll')->waitFor('.nav-tabs .nav-link.active', 40);
        expect(trim((string) $browser->text('.nav-tabs .nav-link.active')))->toBe('Payroll');

        duskClickText($browser, 'Attendance and leave');
        $browser->waitUsing(15, 1, fn (): bool => str_ends_with((string) $browser->driver->getCurrentURL(), '/hrm/attendance'));
        expect(trim((string) $browser->text('.nav-tabs .nav-link.active')))->toBe('Attendance and leave');

        $browser->visit('/hrm/manage')->waitFor('.nav-tabs .nav-link.active', 30);
        expect(trim((string) $browser->text('.nav-tabs .nav-link.active')))->toBe('Rates and setup');

        $this->assertNoBrowserErrors($browser);
    });
});
