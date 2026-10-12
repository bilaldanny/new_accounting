<?php

namespace App\Console\Commands;

use App\Services\Documents\DocumentReminders;
use Illuminate\Console\Command;

/**
 * Sends the due and overdue reminders of every company that has active reminder rules.
 */
class SendDocumentReminders extends Command
{
    protected $signature = 'documents:send-reminders {--company= : One company id}';

    protected $description = 'Send the automatic due and overdue invoice reminders';

    public function handle(DocumentReminders $reminders): int
    {
        $result = $reminders->runDue($this->option('company') ? (int) $this->option('company') : null);
        $this->info("Sent {$result['sent']}, not sent {$result['not_sent']}, skipped {$result['skipped']}.");

        return self::SUCCESS;
    }
}
