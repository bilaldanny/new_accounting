<?php

namespace App\Console\Commands;

use App\Services\FollowUpReminders;
use Illuminate\Console\Command;

/**
 * Notifies the assignees of CRM follow-ups that are due soon or overdue (once each). Run hourly from the scheduler.
 */
class SendCrmReminders extends Command
{
    protected $signature = 'crm:send-reminders {--hours=24 : Remind this many hours ahead}';

    protected $description = 'Send reminders for due and overdue CRM follow-ups';

    public function handle(FollowUpReminders $reminders): int
    {
        $result = $reminders->run((int) $this->option('hours'));
        $this->info("Reminded {$result['reminded']} follow-up(s).");

        return self::SUCCESS;
    }
}
