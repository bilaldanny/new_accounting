<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Removes duplicate `permissions` rows: more than one row for the same role, menu and scope (company, branch,
 * department). There is no unique key on the table, and live data has 5 such pairs (role 2, the `/role` page
 * and its four action rows) from the bulk import of 2026-08-15, where each menu was listed once on (status 1) and
 * once off (status 0).
 *
 * A permission is granted when ANY row of the role for the menu has status 1 (Menu::permittedMenusQuery), so the
 * effective access is unchanged by keeping the row that grants: per group the lowest-id status-1 row is kept, or
 * the lowest-id row when none grants, and the others are deleted. Rows that differ in company, branch or
 * department are different permissions and are never merged. Nothing else is touched.
 *
 * The permission caches of the affected roles are flushed. `down()` cannot bring the removed rows back (they
 * carried no information the kept row does not).
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('permissions')
            ->select('role_id', 'menu_id', 'company_id', 'branch_id', 'department_id')
            ->groupBy('role_id', 'menu_id', 'company_id', 'branch_id', 'department_id')
            ->havingRaw('count(*) > 1')
            ->get();

        $roles = [];

        foreach ($groups as $group) {
            $rows = DB::table('permissions')
                ->where('role_id', $group->role_id)
                ->where('menu_id', $group->menu_id)
                ->where(fn ($query) => $this->sameScope($query, 'company_id', $group->company_id))
                ->where(fn ($query) => $this->sameScope($query, 'branch_id', $group->branch_id))
                ->where(fn ($query) => $this->sameScope($query, 'department_id', $group->department_id))
                ->orderBy('id')
                ->get(['id', 'status']);

            $keep = $rows->firstWhere('status', 1) ?? $rows->first();

            DB::table('permissions')->whereIn('id', $rows->pluck('id')->reject(fn (mixed $id): bool => (int) $id === (int) $keep->id))->delete();

            $roles[(int) $group->role_id] = true;
        }

        foreach (array_keys($roles) as $roleId) {
            Cache::forget("user_menu_permissions_tree:{$roleId}");
            Cache::forget("user_permission_paths:{$roleId}");
            Cache::forget("user_menu_permissions:{$roleId}");
        }
    }

    public function down(): void
    {
        // the removed rows were exact duplicates in effect; there is nothing to put back
    }

    private function sameScope(mixed $query, string $column, mixed $value): void
    {
        $value === null ? $query->whereNull($column) : $query->where($column, $value);
    }
};
