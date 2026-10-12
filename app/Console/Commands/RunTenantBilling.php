<?php

namespace App\Console\Commands;

use App\Services\Saas\TenantLifecycle;
use Illuminate\Console\Command;

/**
 * The daily SaaS housekeeping: usage snapshots, subscriptions set to end at the period end, overdue reminders and suspension
 * after the grace days, and the purge of tenants whose confirmed deletion grace period has ended.
 */
class RunTenantBilling extends Command
{
    protected $signature = 'tenants:run-billing {--company= : One company id}';

    protected $description = 'Snapshot usage, run billing reminders and suspensions, purge due tenant deletions';

    public function handle(TenantLifecycle $lifecycle): int
    {
        $company = $this->option('company') ? (int) $this->option('company') : null;
        $snapshots = $company === null ? $lifecycle->snapshotAll() : 0;
        $billing = $lifecycle->runBilling($company);
        $purged = $lifecycle->purgeDue($company);

        $this->info("Snapshots {$snapshots}; cancelled {$billing['cancelled']}, reminders {$billing['reminders']}, suspended {$billing['suspended']}; purged {$purged['purged']}.");

        return self::SUCCESS;
    }
}
