<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fixed asset depreciation for last month, on the 1st. Needs the server's cron to call `schedule:run`; it can always be run by hand.
Schedule::command('assets:run-depreciation')->monthlyOn(1, '02:00');

// Standing (recurring) invoices that have come due, once a day.
Schedule::command('invoices:generate-recurring')->dailyAt('03:00');

// Journal schedules (recurring / reversing journals, recurring expenses, amortization, accruals), once a day.
Schedule::command('journals:run-schedules')->dailyAt('03:30');
