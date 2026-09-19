<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Grants the default company admin role (`companyadmin`, the global role every new company's admin
 * is created with) the visible "Journal Entry Approval" list row added by
 * 2026_09_19_162000_add_journal_entry_approval_menu.
 *
 * The hidden approve and reject rows (`/journalentry/:id/approve|reject`) were already granted to
 * this role; without the list row the approval page was not in its sidebar. Superadmin is always
 * allowed and other roles are left alone.
 *
 * Follows 2026_09_19_072122_grant_companyadmin_new_menu_permissions: roles only get `permissions`
 * rows through the Role Permission screen, so migrations that add menus never grant anything.
 */
return new class extends Migration
{
    private const PATH = '/journalentry/approval';

    public function up(): void
    {
        $roleId = $this->companyAdminRoleId();
        $menuId = $this->menuId();

        if ($roleId === null || $menuId === null) {
            return;
        }

        $existing = DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $menuId);

        if ($existing->exists()) {
            $existing->update(['status' => 1, 'updated_at' => now()]);
        } else {
            DB::table('permissions')->insert([
                'company_id' => null,
                'branch_id' => null,
                'department_id' => null,
                'role_id' => $roleId,
                'menu_id' => $menuId,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->flushMenuCaches($roleId);
    }

    public function down(): void
    {
        $roleId = $this->companyAdminRoleId();
        $menuId = $this->menuId();

        if ($roleId === null || $menuId === null) {
            return;
        }

        DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $menuId)->delete();

        $this->flushMenuCaches($roleId);
    }

    private function companyAdminRoleId(): ?int
    {
        $id = DB::table('roles')
            ->where('name', 'companyadmin')
            ->whereNull('company_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function menuId(): ?int
    {
        $id = DB::table('menus')
            ->whereNull('deleted_at')
            ->where('route_path', self::PATH)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function flushMenuCaches(int $roleId): void
    {
        Cache::forget("user_menu_permissions_tree:{$roleId}");
        Cache::forget("user_permission_paths:{$roleId}");
        Cache::forget("user_menu_permissions:{$roleId}");
    }
};
