<?php

namespace App\Console\Commands;

use App\Services\Approvals\ApprovalWorkflows;
use Illuminate\Console\Command;

/**
 * Opens an approval request for every waiting document that matches a workflow and notifies the approvers of its current level.
 */
class NotifyPendingApprovals extends Command
{
    protected $signature = 'approvals:notify-pending {--company= : One company id}';

    protected $description = 'Open approval requests for waiting documents and notify their approvers';

    public function handle(ApprovalWorkflows $workflows): int
    {
        $result = $workflows->openPending($this->option('company') ? (int) $this->option('company') : null);
        $this->info("Opened {$result['opened']} request(s), notified for {$result['notified']}.");

        return self::SUCCESS;
    }
}
