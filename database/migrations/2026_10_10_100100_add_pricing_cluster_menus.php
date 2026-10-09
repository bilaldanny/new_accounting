<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products & Catalog Phase 2, pricing cluster menus, all granted to the global companyadmin role:
 *  - Bulk Price Update page next to the Product page (`/product` anchor) with its hidden apply row;
 *  - the hidden `/sell/price-override` row under the Sell page (`/sell` anchor): a user holding it may sell outside
 *    a variation's minimum / maximum price;
 *  - the Price & Cost History report next to the Stock report (`/report/stock` anchor).
 * An anchor that is missing (a fresh schema) skips its part. Other roles are granted from the Role Permission screen.
 */
return new class extends Migration
{
    private const PAGE = '/bulkpriceupdate';

    private const APPLY = '/bulkpriceupdate/apply';

    private const OVERRIDE = '/sell/price-override';

    private const REPORT = '/report/price-history';

    public function up(): void
    {
        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $created = [];

        $productGroup = DB::table('menus')->where('route_path', '/product')->value('parent_id');

        if ($productGroup !== null && ! DB::table('menus')->where('route_path', self::PAGE)->exists()) {
            $pageId = $this->insert($productGroup, 'Bulk Price Update', 'Tags', 'bulkpriceupdate', self::PAGE, false, $hasAdminColumn);
            $created = [$pageId, $this->insert($pageId, 'Apply Bulk Price Update', 'Grid', 'bulkpriceupdate.apply', self::APPLY, true, $hasAdminColumn)];
        }

        $sellId = DB::table('menus')->where('route_path', '/sell')->value('id');

        if ($sellId !== null && ! DB::table('menus')->where('route_path', self::OVERRIDE)->exists()) {
            $created[] = $this->insert($sellId, 'Sell Outside Price Limits', 'Grid', 'sell.priceoverride', self::OVERRIDE, true, $hasAdminColumn);
        }

        $reportGroup = DB::table('menus')->where('route_path', '/report/stock')->value('parent_id');

        if ($reportGroup !== null && ! DB::table('menus')->where('route_path', self::REPORT)->exists()) {
            $created[] = $this->insert($reportGroup, 'Product Cost & Price History', 'FileBarChart', 'report.price-history', self::REPORT, false, $hasAdminColumn);
        }

        $roleId = DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->whereNull('deleted_at')->orderBy('id')->value('id');

        if ($roleId !== null && $created !== []) {
            DB::table('permissions')->insert(array_map(fn (int $menuId): array => [
                'company_id' => null, 'branch_id' => null, 'department_id' => null,
                'role_id' => $roleId, 'menu_id' => $menuId, 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], $created));
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->whereIn('route_path', [self::PAGE, self::APPLY, self::OVERRIDE, self::REPORT])->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
    }

    private function insert(int $parentId, string $name, string $icon, string $routeName, string $path, bool $hidden, bool $hasAdminColumn): int
    {
        $row = [
            'parent_id' => $parentId,
            'name' => $name,
            'icon' => $icon,
            'route_name' => $routeName,
            'route_path' => $path,
            'menu_color' => '#199683',
            'sort_order' => (int) DB::table('menus')->where('parent_id', $parentId)->max('sort_order') + 1,
            'is_hidden' => $hidden ? 1 : 0,
            'is_active' => 1,
            'is_permission' => 1,
            'type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($hasAdminColumn) {
            $row['is_admin'] = 0;
        }

        return (int) DB::table('menus')->insertGetId($row);
    }

    private function flushMenuCaches(): void
    {
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            Cache::forget("user_menu_permissions_tree:{$roleId}");
            Cache::forget("user_permission_paths:{$roleId}");
            Cache::forget("user_menu_permissions:{$roleId}");
        }
    }
};
