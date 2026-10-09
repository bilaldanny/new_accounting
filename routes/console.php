<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fixed asset depreciation for last month, on the 1st. Needs the server's cron to call `schedule:run`; it can always be run by hand.
Schedule::command('assets:run-depreciation')->monthlyOn(1, '02:00');
