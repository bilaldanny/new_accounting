<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports & Analytics Phase 2: the 14 analytics report pages, next to the Stock report (same parent group,
 * found through the `/report/stock` anchor row; skipped when it is missing), each with its own path as the
 * permission its controller checks. They are granted to the global companyadmin role; other roles are granted
 * from the Role Permission screen.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const REPORTS = [
        '/report/sales-discount' => 'Sales Discount Report',
        '/report/purchase-price-trend' => 'Purchase Price Trend',
        '/report/supplier-performance' => 'Supplier Performance',
        '/report/backorder' => 'Backorder Report',
        '/report/fsn' => 'Fast / Slow-Moving & Dead Stock',
        '/report/cash-collection' => 'Cash Collection Report',
        '/report/payment-account' => 'Payments by Payment Account',
        '/report/payment-age' => 'Payments by Age',
        '/report/consolidated-branch' => 'Consolidated Multi-Branch',
        '/report/financial-ratios' => 'Financial Ratios',
        '/report/cash-flow' => 'Cash Flow Statement',
        '/report/register' => 'Register, Z & Cashier Report',
        '/report/activity-summary' => 'Activity Log & User Audit Trail',
        '/report/change-history' => 'Data Change History',
    ];

    public function up(): void
    {
        $groupId = DB::table('menus')->where('route_path', '/report/stock')->value('parent_id');

        if ($groupId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $roleId = DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->whereNull('deleted_at')->orderBy('id')->value('id');
        $sort = (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order');

        foreach (self::REPORTS as $path => $name) {
            $id = DB::table('menus')->where('route_path', $path)->value('id');

            if ($id === null) {
                $row = [
                    'parent_id' => $groupId,
                    'name' => $name,
                    'icon' => 'FileBarChart',
                    'route_name' => 'report.'.substr($path, strlen('/report/')),
                    'route_path' => $path,
                    'menu_color' => '#199683',
                    'sort_order' => ++$sort,
                    'is_hidden' => 0,
                    'is_active' => 1,
                    'is_permission' => 1,
                    'type' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($hasAdminColumn) {
                    $row['is_admin'] = 0;
                }

                $id = DB::table('menus')->insertGetId($row);
            }

            if ($roleId !== null && ! DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $id)->exists()) {
                DB::table('permissions')->insert([
                    'company_id' => null, 'branch_id' => null, 'department_id' => null,
                    'role_id' => $roleId, 'menu_id' => $id, 'status' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->whereIn('route_path', array_keys(self::REPORTS))->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
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
