<?php

namespace App\Console\Commands;

use App\Services\SubscriptionBilling;
use Illuminate\Console\Command;

/**
 * Generates the invoices customer subscriptions have come due for, flags overdue ones and sends the due-soon and dunning
 * reminders (once per invoice and kind). Run daily from the scheduler.
 */
class RunCustomerSubscriptions extends Command
{
    protected $signature = 'customersubscriptions:run {--company= : One company id}';

    protected $description = 'Generate customer subscription invoices and send reminders';

    public function handle(SubscriptionBilling $billing): int
    {
        $result = $billing->run($this->option('company') ? (int) $this->option('company') : null);
        $this->info("Generated {$result['generated']} invoice(s), {$result['upcoming']} due-soon and {$result['dunning']} dunning reminder(s), flagged {$result['flagged_overdue']} overdue.");

        return self::SUCCESS;
    }
}
