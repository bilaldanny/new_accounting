<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Grants the default company admin role (`companyadmin`, the global role every new company's admin
 * is created with) the menu rows added by the 2026-09-19 quick wins: Sell Return, Sell Payment,
 * Purchase Payment, Product import/export/trash/restore, Low Stock and Reports.
 *
 * Roles only get `permissions` rows through the Role Permission screen, so without this the
 * company admin would not see or be allowed to use any of them. Superadmin is always allowed.
 */
return new class extends Migration
{
    /**
     * Route paths of the leaf and hidden permission rows to grant.
     *
     * @var list<string>
     */
    private const PATHS = [
        '/sell/return',
        '/sell/return/add',
        '/sell/return/:id/edit',
        '/sell/return/delete',
        '/sell/return/restore',
        '/sell/return/:id/view',
        '/sell/payment',
        '/sell/payment/add',
        '/sell/payment/:id/edit',
        '/sell/payment/delete',
        '/purchase/payment',
        '/purchase/payment/add',
        '/purchase/payment/:id/edit',
        '/purchase/payment/delete',
        '/product/export',
        '/product/import',
        '/product/trash',
        '/product/restore',
        '/lowstock',
        '/lowstock/export',
        '/report/ledger',
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
        $ids = DB::table('menus')
            ->whereNull('deleted_at')
            ->whereIn('route_path', self::PATHS)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $groupId = DB::table('menus')
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->where('name', 'Reports')
            ->where('type', 2)
            ->value('id');

        if ($groupId !== null) {
            $ids[] = (int) $groupId;
        }

        return $ids;
    }

    private function flushMenuCaches(int $roleId): void
    {
        Cache::forget("user_menu_permissions_tree:{$roleId}");
        Cache::forget("user_permission_paths:{$roleId}");
        Cache::forget("user_menu_permissions:{$roleId}");
    }
};
