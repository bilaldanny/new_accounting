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

// CRM follow-up reminders, hourly; customer subscription renewals, reminders and dunning, daily.
Schedule::command('crm:send-reminders')->hourly();
Schedule::command('customersubscriptions:run')->dailyAt('04:00');

// Approval Center: open requests for waiting documents and notify the approvers of their level, hourly.
Schedule::command('approvals:notify-pending')->hourly();

// SaaS: usage snapshots, cancel at period end, overdue reminders and suspension, and due tenant deletions, daily.
Schedule::command('tenants:run-billing')->dailyAt('05:00');
