<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\JournalScheduleEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Posts what every company's journal schedules (recurring and reversing journals, recurring expenses, amortization, accruals)
 * have come due for, up to a day (default: today). Safe to run again: each occurrence is posted once. A schedule that reaches a
 * locked period stops there and carries on from that day after the period is reopened.
 */
class RunJournalSchedules extends Command
{
    protected $signature = 'journals:run-schedules {--date= : Post up to this day, Y-m-d (default: today)} {--company= : One company id}';

    protected $description = 'Post the vouchers journal schedules have come due for';

    public function handle(JournalScheduleEngine $engine): int
    {
        if (! JournalScheduleEngine::available()) {
            $this->info('Journal schedules are not set up yet.');

            return self::SUCCESS;
        }

        $date = (string) ($this->option('date') ?: now()->toDateString());

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $this->error('The date must look like 2026-09-30.');

            return self::FAILURE;
        }

        $posted = 0;

        Company::query()
            ->when($this->option('company'), fn ($q) => $q->whereKey((int) $this->option('company')))
            ->each(function (Company $company) use ($engine, $date, &$posted): void {
                $actor = User::query()->where('company_id', $company->id)->whereHas('role', fn ($q) => $q->where('name', 'companyadmin'))->orderBy('id')->first();

                if ($actor === null) {
                    return;
                }

                Auth::setUser($actor);
                $result = $engine->run((int) $company->id, $date);
                $posted += $result['posted'] + $result['reversed'];

                foreach ($result['blocked'] as $blocked) {
                    $this->warn("Company {$company->id}: '{$blocked['name']}' stopped at {$blocked['date']}: {$blocked['reason']}");
                }
            });

        $this->info("Done: {$posted} voucher(s) posted up to {$date}.");

        return self::SUCCESS;
    }
}
