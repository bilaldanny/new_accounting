<?php

namespace App\Console\Commands;

use App\Services\System\ShowcaseDemoCompanies;
use Illuminate\Console\Command;

/**
 * Builds several demonstration companies with months of purchases, sales, returns, payments and payroll, so the dashboards look like a
 * platform many companies use. Never touches a real company: it only creates, finds or removes the companies coded DEMO-SHOW-n.
 */
class BuildShowcaseCompanies extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:showcase {--companies=4 : How many companies (1 to 6)} {--months=4 : Months of history, the current month included} {--sales=30 : Sales a month per company} {--purchases=10 : Purchases a month per company} {--remove : Remove the showcase companies instead} {--fresh : Remove them first, then build again} {--force : Allow this when the environment is production}';

    /**
     * @var string
     */
    protected $description = 'Build showcase demo companies (DEMO-SHOW-n) with months of sales, purchases, returns, payments and payroll';

    public function handle(ShowcaseDemoCompanies $showcase): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('This is a production environment. The showcase adds whole companies with months of data; run it again with --force if that is intended.');

            return self::FAILURE;
        }

        if ($this->option('remove') || $this->option('fresh')) {
            $removed = $showcase->remove();
            $this->line("Removed {$removed} showcase ".($removed === 1 ? 'company' : 'companies').'.');

            if ($this->option('remove')) {
                return self::SUCCESS;
            }
        }

        $results = $showcase->build((int) $this->option('companies'), (int) $this->option('months'), (int) $this->option('sales'), (int) $this->option('purchases'));
        $rows = [];

        foreach ($results as $result) {
            $rows[] = [
                $result['code'], $result['created'] ? 'created' : 'already there', $result['summary']['sales'] ?? '-', $result['summary']['purchases'] ?? '-',
                $result['summary']['payments'] ?? '-', $result['email'], $result['password'] ?? '(unchanged)',
            ];
        }

        $this->table(['Company', 'State', 'Sales', 'Purchases', 'Payments', 'Admin sign-in', 'Password (shown once)'], $rows);

        return self::SUCCESS;
    }
}
