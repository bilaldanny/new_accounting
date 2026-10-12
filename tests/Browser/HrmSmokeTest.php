<?php

use Laravel\Dusk\Browser;

/**
 * A real-browser smoke check of HR and Payroll: an employee is added through the form and appears in the list, and the rates tab
 * carries the reminder that every statutory rate is the accountant's to enter and verify. Screenshots in tests/Browser/screenshots.
 */
test('an employee is added on the HR page and the rates tab says the rates are entered by the accountant', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())
            ->visit('/hrm')
            ->waitForText('HR and Payroll', 30)
            ->waitFor('[aria-label="Employee no"]', 20)
            ->type('[aria-label="Employee no"]', 'SMK-1')
            ->type('[aria-label="First name"]', 'Smoke')
            ->type('[aria-label="Last name"]', 'Tester')
            ->press('Save employee')
            ->waitForText('SMK-1', 20)
            ->assertSee('Smoke Tester')
            ->screenshot('wave7-hr-employees')
            ->press('Rates and setup')
            ->waitForText('rates accountant se verify karein', 20)
            ->assertSee('No statutory rate entered')
            ->screenshot('wave7-hr-rates');

        $this->assertNoBrowserErrors($browser);
    });
});
