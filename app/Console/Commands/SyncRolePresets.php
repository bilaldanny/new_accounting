<?php

namespace App\Console\Commands;

use App\Support\RolePresets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates the starter roles (Accountant, Cashier, Sales Representative, Manager, Warehouse Keeper) for one company branch
 * and turns their menus on. A role that already exists is not created again, only topped up with the menus it is missing;
 * nothing is ever switched off, so a company's own tweaks stay.
 */
class SyncRolePresets extends Command
{
    /**
     * @var string
     */
    protected $signature = 'roles:sync-presets {company : Company id} {branch : Branch id} {--only=* : Only these presets, e.g. --only=Cashier} {--dry-run : Show what would be created and granted without changing anything}';

    /**
     * @var string
     */
    protected $description = 'Create the starter roles for a company branch and grant them their default menus';

    public function handle(): int
    {
        $companyId = (int) $this->argument('company');
        $branchId = (int) $this->argument('branch');

        if (! DB::table('branches')->where('id', $branchId)->where('company_id', $companyId)->exists()) {
            $this->error("Branch {$branchId} does not belong to company {$companyId}.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach (RolePresets::sync($companyId, $branchId, (array) $this->option('only'), $dryRun) as $preset => $outcome) {
            if ($dryRun) {
                $this->line("{$preset}: would ".($outcome['created'] ? 'create the role and ' : 'top up the existing role and ')."grant up to {$outcome['menus']} menus (dry run, nothing changed).");
            } else {
                $this->info("{$preset}: {$outcome['menus']} menus granted.");
            }
        }

        return self::SUCCESS;
    }
}
