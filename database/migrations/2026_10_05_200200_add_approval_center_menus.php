<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval Center (Phase 1, easy half): sidebar pages under the existing "Approval" group for purchase
 * returns, stock adjustments, stock transfers, cash collections, price lists and credit limit requests.
 * Each page's own path is the permission its controller checks (same convention as `/purchase/approval`);
 * credit limit also has hidden `/creditlimit/reject` and `/creditlimit/add` rows. Skips when the Approval
 * group is missing. Permissions are not granted here.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const PAGES = [
        ['Purchase Return Approval', 'purchasereturnapprovals', '/purchasereturn/approval', 'Undo2'],
        ['Stock Adjustment Approval', 'stockadjustmentapprovals', '/stockadjustment/approval', 'SlidersHorizontal'],
        ['Stock Transfer Approval', 'stocktransferapprovals', '/stocktransfer/approval', 'ArrowLeftRight'],
        ['Cash Collection Approval', 'cashcollectionapprovals', '/cashcollection/approval', 'Wallet'],
        ['Price List Approval', 'pricelistapprovals', '/pricelist/approval', 'PieChart'],
        ['Credit Limit Approval', 'creditlimitapprovals', '/creditlimit/approval', 'ShieldCheck'],
    ];

    private const HIDDEN = [
        ['Reject Credit Limit Request', 'creditlimitapprovals.reject', '/creditlimit/reject'],
        ['Add Credit Limit Request', 'creditlimit.add', '/creditlimit/add'],
    ];

    public function up(): void
    {
        $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Approval')->where('type', 2)->value('id');

        if ($groupId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $creditPageId = null;

        foreach (self::PAGES as [$name, $routeName, $path, $icon]) {
            if (DB::table('menus')->where('route_path', $path)->exists()) {
                continue;
            }

            $id = DB::table('menus')->insertGetId($this->row([
                'parent_id' => $groupId,
                'name' => $name,
                'icon' => $icon,
                'route_name' => $routeName,
                'route_path' => $path,
                'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
                'is_hidden' => 0,
            ], $hasAdminColumn));

            if ($path === '/creditlimit/approval') {
                $creditPageId = $id;
            }
        }

        $creditPageId ??= DB::table('menus')->where('route_path', '/creditlimit/approval')->value('id');

        if ($creditPageId !== null) {
            foreach (self::HIDDEN as $index => [$name, $routeName, $path]) {
                if (DB::table('menus')->where('route_path', $path)->exists()) {
                    continue;
                }

                DB::table('menus')->insert($this->row([
                    'parent_id' => $creditPageId,
                    'name' => $name,
                    'icon' => 'Grid',
                    'route_name' => $routeName,
                    'route_path' => $path,
                    'sort_order' => $index + 1,
                    'is_hidden' => 1,
                ], $hasAdminColumn));
            }
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $paths = array_merge(array_column(self::PAGES, 2), array_column(self::HIDDEN, 2));
        $ids = DB::table('menus')->whereIn('route_path', $paths)->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function row(array $row, bool $hasAdminColumn): array
    {
        $row += [
            'type' => 1,
            'menu_color' => '#6a0dad',
            'is_active' => 1,
            'is_permission' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($hasAdminColumn) {
            $row['is_admin'] = 0;
        }

        return $row;
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
