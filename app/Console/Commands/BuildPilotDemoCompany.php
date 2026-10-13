<?php

namespace App\Console\Commands;

use App\Services\System\PilotDemoCompany;
use Illuminate\Console\Command;

/**
 * Creates (or removes) the separate pilot demonstration company with a month of realistic data. It never touches an existing company:
 * the only company it creates, finds or removes is the one with the code DEMO-PILOT.
 */
class BuildPilotDemoCompany extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:pilot {--remove : Remove the pilot demo company (only the one coded DEMO-PILOT) instead of building it} {--fresh : Remove the pilot demo company first, then build it again} {--force : Allow this to run when the application environment is production}';

    /**
     * @var string
     */
    protected $description = 'Build the DEMO-PILOT company: Pakistani trading data, a payroll month and a production order, in its own tenant';

    public function handle(PilotDemoCompany $demo): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('This is a production environment. The pilot demo adds a whole company with a month of data; run it again with --force if that is intended.');

            return self::FAILURE;
        }

        if ($this->option('remove') || $this->option('fresh')) {
            $this->line($demo->remove() ? 'The pilot demo company was removed.' : 'There is no pilot demo company to remove.');

            if ($this->option('remove')) {
                return self::SUCCESS;
            }
        }

        $result = $demo->build();

        if (! $result['created']) {
            $this->info("The pilot demo company already exists (company {$result['company_id']}); nothing was changed. Use --fresh to build it again.");

            return self::SUCCESS;
        }

        $this->info("Pilot demo company {$result['code']} created (company {$result['company_id']}).");
        $this->table(['Item', 'Count'], collect($result['summary'])->map(fn (int $count, string $item): array => [str_replace('_', ' ', $item), $count])->values()->all());
        $this->line("Sign in as {$result['email']} with the password {$result['password']} (shown once, write it down).");

        return self::SUCCESS;
    }
}
