<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Grants the default company admin role (`companyadmin`, the global role every new company's admin is
 * created with) the 30 report pages added by the report menu migrations, and the hidden `/export` row
 * of each (the permission the table's Export buttons check): 60 `permissions` rows.
 *
 * The transaction reports (2026_09_20_214054), party, product, stock, accounts and financial reports
 * (2026_09_21_1200..1600) only add menus; roles get `permissions` rows through the Role Permission
 * screen, so without this the reports are not in the company admin's sidebar. The Customer / Supplier
 * Ledger (`/report/ledger`) was granted earlier by 2026_09_19_072122 and is not touched. Superadmin is
 * always allowed and other roles are left alone.
 *
 * Follows 2026_09_20_090000_grant_companyadmin_journal_entry_approval_menu: idempotent (an existing row
 * is re-enabled, a missing one added), a menu row that does not exist is skipped, and `down()` removes
 * only these rows.
 */
return new class extends Migration
{
    /**
     * Report page paths; each also has `<path>/export`.
     *
     * @var list<string>
     */
    private const PAGES = [
        '/report/purchase',
        '/report/purchase-return',
        '/report/sell',
        '/report/sell-return',
        '/report/purchase-payment',
        '/report/sell-payment',
        '/report/stock-adjustment',
        '/report/expense',
        '/report/customer-outstanding',
        '/report/supplier-outstanding',
        '/report/customer-supplier',
        '/report/customer-group',
        '/report/customer-aging',
        '/report/supplier-aging',
        '/report/product-purchase',
        '/report/product-sell',
        '/report/product-sell-summary',
        '/report/item-profit-loss',
        '/report/item-purchase',
        '/report/item-sell',
        '/report/purchase-sale',
        '/report/tax',
        '/report/trending-products',
        '/report/stock',
        '/report/stock-transfer',
        '/report/account-ledger',
        '/report/trial-balance',
        '/report/vouchers',
        '/report/profit-loss',
        '/report/balance-sheet',
    ];

    public function up(): void
    {
        $roleId = $this->companyAdminRoleId();
        $menuIds = $this->menuIds();

        if ($roleId === null || $menuIds === []) {
            return;
        }

        $existing = DB::table('permissions')
            ->where('role_id', $roleId)
            ->whereIn('menu_id', $menuIds)
            ->pluck('menu_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($existing !== []) {
            DB::table('permissions')
                ->where('role_id', $roleId)
                ->whereIn('menu_id', $existing)
                ->update(['status' => 1, 'updated_at' => now()]);
        }

        $missing = array_values(array_diff($menuIds, $existing));

        if ($missing !== []) {
            DB::table('permissions')->insert(array_map(fn (int $menuId): array => [
                'company_id' => null,
                'branch_id' => null,
                'department_id' => null,
                'role_id' => $roleId,
                'menu_id' => $menuId,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ], $missing));
        }

        $this->flushMenuCaches($roleId);
    }

    public function down(): void
    {
        $roleId = $this->companyAdminRoleId();
        $menuIds = $this->menuIds();

        if ($roleId === null || $menuIds === []) {
            return;
        }

        DB::table('permissions')->where('role_id', $roleId)->whereIn('menu_id', $menuIds)->delete();

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
     * The ids of the report pages and their export rows that exist.
     *
     * @return list<int>
     */
    private function menuIds(): array
    {
        $paths = [];

        foreach (self::PAGES as $page) {
            array_push($paths, $page, $page.'/export');
        }

        return DB::table('menus')
            ->whereNull('deleted_at')
            ->whereIn('route_path', $paths)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function flushMenuCaches(int $roleId): void
    {
        Cache::forget("user_menu_permissions_tree:{$roleId}");
        Cache::forget("user_permission_paths:{$roleId}");
        Cache::forget("user_menu_permissions:{$roleId}");
    }
};
