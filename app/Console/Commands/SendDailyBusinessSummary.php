<?php

namespace App\Console\Commands;

use App\Services\Dashboard\DailyBusinessSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sends the daily business summary of every company that switched it on and whose send hour has come (or is past, so a missed run
 * catches up; a recipient is only ever sent a given day once). `--company` limits it, `--date` names the day (yesterday's by default
 * before the send hour, today's after).
 */
class SendDailyBusinessSummary extends Command
{
    protected $signature = 'dashboard:daily-summary {--company= : One company id} {--date= : The day to summarise (Y-m-d)} {--force : Ignore the switch and the hour}';

    protected $description = 'Send the daily business summary by email, WhatsApp or SMS';

    public function handle(DailyBusinessSummary $summary): int
    {
        if (! Schema::hasTable('daily_summary_settings')) {
            $this->info('The daily summary is not installed here.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        $force = (bool) $this->option('force');

        $companies = DB::table('daily_summary_settings')->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))
            ->when(! $force, fn ($q) => $q->where('enabled', true)->where('send_hour', '<=', (int) now()->format('G')))->pluck('company_id');

        foreach ($companies as $companyId) {
            $result = $summary->send((int) $companyId, (string) ($this->option('date') ?: now()->toDateString()), $force);
            $sent += $result['sent'];
            $failed += $result['failed'];
        }

        $this->info("Sent {$sent}, failed {$failed}.");

        return self::SUCCESS;
    }
}
