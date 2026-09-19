<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Grants the default company admin role (`companyadmin`, the global role every new company's admin
 * is created with) the business modules that were added after its permissions were last set up:
 * Stock Transfer and Stock Adjustment (main rows; their add/edit/delete rows were already granted),
 * Expense, Deposit, Fund Transfer, Warehouse, Consumer, Bank Issuer, Price List and Print Label.
 *
 * Deliberately not granted: the system settings (Currencies, Timezones, Software Setting, Country,
 * State, City, Settings group) and the rows that are explicitly denied to this role (Menus, Company,
 * Role, User Management, Company Settings).
 *
 * Follows 2026_09_19_072122_grant_companyadmin_new_menu_permissions. Roles only get `permissions`
 * rows through the Role Permission screen, so migrations that add menus never grant anything.
 */
return new class extends Migration
{
    /**
     * Route paths of the 34 rows to grant.
     *
     * @var list<string>
     */
    private const PATHS = [
        '/stocktransfer',
        '/stockadjustment',
        '/expense',
        '/expense/add',
        '/expense/:id/edit',
        '/expense/:id/view',
        '/expense/delete',
        '/deposit',
        '/deposit/add',
        '/deposit/:id/edit',
        '/deposit/:id/view',
        '/deposit/delete',
        '/fundtransfer',
        '/fundtransfer/add',
        '/fundtransfer/:id/edit',
        '/fundtransfer/:id/view',
        '/fundtransfer/delete',
        '/warehouse',
        '/warehouse/add',
        '/warehouse/:id/edit',
        '/warehouse/delete',
        '/consumer',
        '/consumer/add',
        '/consumer/:id/edit',
        '/consumer/delete',
        '/bankissuer',
        '/bankissuer/add',
        '/bankissuer/:id/edit',
        '/bankissuer/delete',
        '/pricelist',
        '/pricelist/add',
        '/pricelist/:id/edit',
        '/pricelist/delete',
        '/printlabel',
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
