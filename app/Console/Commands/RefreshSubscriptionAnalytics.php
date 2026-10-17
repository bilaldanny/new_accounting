<?php

namespace App\Console\Commands;

use App\Services\Reports\CustomerSubscriptionAnalyticsSnapshot;
use Illuminate\Console\Command;

/**
 * Rebuilds the stored Subscription Analytics snapshots (the page reads them instead of working the numbers out on every visit).
 * Run daily from the scheduler; it can always be run by hand.
 */
class RefreshSubscriptionAnalytics extends Command
{
    protected $signature = 'customersubscriptions:refresh-analytics {--company= : One company id}';

    protected $description = 'Rebuild the Subscription Analytics snapshots';

    public function handle(): int
    {
        $made = CustomerSubscriptionAnalyticsSnapshot::refreshAll($this->option('company') ? (int) $this->option('company') : null);
        $this->info("Rebuilt the snapshots of {$made} scope(s).");

        return self::SUCCESS;
    }
}
