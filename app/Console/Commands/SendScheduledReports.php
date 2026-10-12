<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportScheduler;
use Illuminate\Console\Command;

/**
 * Sends the scheduled reports that are due today (daily, weekly or monthly), each as the person who created it.
 */
class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = 'Email the scheduled reports that are due';

    public function handle(ReportScheduler $scheduler): int
    {
        $result = $scheduler->runDue();
        $this->info("Sent {$result['sent']}, failed {$result['failed']}, skipped {$result['skipped']}.");

        return self::SUCCESS;
    }
}
