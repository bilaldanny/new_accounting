<?php

namespace App\Console\Commands;

use App\Services\System\DemoHistoryCompany;
use Illuminate\Console\Command;

/**
 * Builds (or removes) the separate demonstration company with twelve months of history. It never touches another company: the only
 * company it creates, finds or removes is the one coded DEMO-HISTORY.
 */
class BuildDemoHistory extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:history {--remove : Remove the DEMO-HISTORY company instead of building it} {--fresh : Remove it first, then build it again} {--months=12 : Months of history} {--sales=36 : Sales in an ordinary month} {--purchases=8 : Purchases in an ordinary month} {--force : Allow this to run when the application environment is production}';

    /**
     * @var string
     */
    protected $description = 'Build the DEMO-HISTORY company: twelve months of Pakistani trade with seasons, expenses, payroll, attendance, leave and leads, in its own tenant';

    public function handle(DemoHistoryCompany $demo): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('This is a production environment. The history demo adds a whole company with a year of data; run it again with --force if that is intended.');

            return self::FAILURE;
        }

        if ($this->option('remove') || $this->option('fresh')) {
            $this->line($demo->remove() ? 'The history demo company was removed.' : 'There is no history demo company to remove.');

            if ($this->option('remove')) {
                return self::SUCCESS;
            }
        }

        $result = $demo->build((int) $this->option('months'), (int) $this->option('sales'), (int) $this->option('purchases'));

        if (! $result['created']) {
            $this->info("The history demo company already exists (company {$result['company_id']}); nothing was changed. Use --fresh to build it again.");

            return self::SUCCESS;
        }

        $this->info("History demo company {$result['code']} created (company {$result['company_id']}).");
        $this->table(['Item', 'Count'], collect($result['summary'])->map(fn (int $count, string $item): array => [str_replace('_', ' ', $item), $count])->values()->all());
        $this->line("Sign in as {$result['email']} with the password {$result['password']} (shown once, write it down).");

        return self::SUCCESS;
    }
}
