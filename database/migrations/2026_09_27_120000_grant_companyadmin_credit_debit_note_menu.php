<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Grants the default company admin role (`companyadmin`, the global role every new company's admin
 * is created with) the rows added by 2026_09_27_110000_add_credit_debit_note_menu: the list, add,
 * edit, view and delete rows, the approve/reject rows, and the approval list row.
 *
 * Follows 2026_09_20_120000_grant_companyadmin_voucher_approval_menus: roles only get `permissions`
 * rows through the Role Permission screen, so migrations that add menus never grant anything by
 * default. Superadmin is always allowed and other roles are left alone.
 *
 * File only — not run against the live database. The owner runs this after reviewing it.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const PATHS = [
        '/creditdebitnote',
        '/creditdebitnote/add',
        '/creditdebitnote/:id/edit',
        '/creditdebitnote/:id/view',
        '/creditdebitnote/delete',
        '/creditdebitnote/:id/approve',
        '/creditdebitnote/:id/reject',
        '/creditdebitnote/approval',
    ];

    public function up(): void
    {
        $roleId = $this->companyAdminRoleId();

        if ($roleId === null) {
            return;
        }

        foreach ($this->menuIds() as $menuId) {
            $existing = DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $menuId);

            if ($existing->exists()) {
                $existing->update(['status' => 1, 'updated_at' => now()]);

                continue;
            }

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

        if ($roleId === null) {
            return;
        }

        DB::table('permissions')
            ->where('role_id', $roleId)
            ->whereIn('menu_id', $this->menuIds())
            ->delete();

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

    /**
     * @return list<int>
     */
    private function menuIds(): array
    {
        return DB::table('menus')
            ->whereNull('deleted_at')
            ->whereIn('route_path', self::PATHS)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function flushMenuCaches(int $roleId): void
    {
        Cache::forget("user_menu_permissions_tree:{$roleId}");
        Cache::forget("user_permission_paths:{$roleId}");
        Cache::forget("user_menu_permissions:{$roleId}");
    }
};
