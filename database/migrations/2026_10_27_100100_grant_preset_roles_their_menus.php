<?php

use App\Support\RolePresets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Companies define their own roles, so there is no Accountant or Cashier role to grant the newer features to by id. Any
 * role whose name is one of the starter presets (Accountant, Cashier, Sales Representative, Manager, Warehouse Keeper) is
 * topped up with that preset's menus in its own company / branch, nothing is switched off. A database without such roles
 * is left untouched: `php artisan roles:sync-presets {company} {branch}` creates them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->presetRoles() as [$role, $preset]) {
            RolePresets::grant((int) $role->id, $role->company_id === null ? null : (int) $role->company_id, $role->branch_id === null ? null : (int) $role->branch_id, $preset);
        }
    }

    public function down(): void
    {
        foreach ($this->presetRoles() as [$role, $preset]) {
            RolePresets::revoke((int) $role->id, $preset);
        }
    }

    /**
     * @return list<array{0: object, 1: string}>
     */
    private function presetRoles(): array
    {
        $found = [];

        foreach (DB::table('roles')->whereNull('deleted_at')->where('name', '!=', 'companyadmin')->get() as $role) {
            $preset = RolePresets::presetFor($role->name);

            if ($preset !== null) {
                $found[] = [$role, $preset];
            }
        }

        return $found;
    }
};
