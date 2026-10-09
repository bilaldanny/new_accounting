<?php

namespace App\Console\Commands;

use App\Models\Role;
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

        $only = array_map(Role::normalizeName(...), (array) $this->option('only'));

        foreach (array_keys(RolePresets::PRESETS) as $preset) {
            if ($only !== [] && ! in_array(Role::normalizeName($preset), $only, true)) {
                continue;
            }

            $role = Role::query()->where('company_id', $companyId)->where('branch_id', $branchId)
                ->whereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [Role::normalizeName($preset)])->first();

            if ($this->option('dry-run')) {
                $menus = count(RolePresets::menuIds($preset));
                $this->line("{$preset}: would ".($role === null ? 'create the role and ' : 'top up the existing role and ')."grant up to {$menus} menus (dry run, nothing changed).");

                continue;
            }

            $role ??= Role::query()->create(['name' => $preset, 'company_id' => $companyId, 'branch_id' => $branchId, 'is_active' => true]);

            $count = RolePresets::grant((int) $role->id, $companyId, $branchId, $preset);
            $this->info("{$preset}: {$count} menus granted.");
        }

        return self::SUCCESS;
    }
}
