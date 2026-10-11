<?php

namespace App\Console\Commands;

use App\Services\RecurringInvoices;
use Illuminate\Console\Command;

/**
 * Makes the sales that standing (recurring) invoices have come due for. Run daily from the scheduler; it can always be run by
 * hand, and the Recurring Invoices page has a button for the same thing.
 */
class GenerateRecurringInvoices extends Command
{
    protected $signature = 'invoices:generate-recurring';

    protected $description = 'Generate the sales that recurring invoices have come due for';

    public function handle(RecurringInvoices $generator): int
    {
        if (! RecurringInvoices::supported()) {
            $this->info('Recurring invoices are not set up yet.');

            return self::SUCCESS;
        }

        $result = $generator->generateDue();
        $this->info("Generated {$result['generated']} invoice(s), {$result['failed']} failed.");

        return self::SUCCESS;
    }
}
