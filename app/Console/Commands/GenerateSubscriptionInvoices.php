<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SubscriptionInvoice;
use Illuminate\Console\Command;

/**
 * Generates the next cycle's subscription invoice for every tenant whose billing period is due, and
 * flags overdue unpaid ones. No scheduler is wired up anywhere in this app yet (confirmed: no
 * `withSchedule()` in bootstrap/app.php, no other command is cron-driven) — like the existing
 * `journals:sync-documents` command, this is meant to be invoked by an OS-level cron entry at deploy
 * time, not by Laravel's own scheduler.
 */
class GenerateSubscriptionInvoices extends Command
{
    /**
     * @var string
     */
    protected $signature = 'subscriptions:generate-invoices';

    /**
     * @var string
     */
    protected $description = 'Generate due subscription invoices and flag overdue ones';

    public function handle(): int
    {
        $generated = 0;

        Company::query()
            ->whereNotNull('subscription_plan_id')
            ->whereIn('tenant_status', ['trial', 'active'])
            ->where(function ($q) {
                $q->whereNull('current_period_ends_at')
                    ->orWhereDate('current_period_ends_at', '<=', now()->toDateString());
            })
            ->each(function (Company $company) use (&$generated): void {
                if (SubscriptionInvoice::generateForCompany($company) !== null) {
                    $generated++;
                }
            });

        $overdue = SubscriptionInvoice::flagOverdue();

        $this->info("Generated {$generated} invoice(s). Flagged {$overdue} as overdue.");

        return self::SUCCESS;
    }
}
