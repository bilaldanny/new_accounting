<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Go-live repair of two menu gaps found on the live database.
 *
 * 1. The Serials & Batches and Warehouse Locations pages were never added: their menu migrations look for the parent group
 *    of the `/warehouse` row, but Warehouse is itself a top-level row (no parent), so both migrations skipped the page and
 *    even the companyadmin got a 403 on it. The pages are added here as top-level rows next to Warehouse.
 * 2. The global companyadmin role never got CRM (leads, opportunities, pipeline, activities, analytics), and never got the
 *    hidden add / edit / delete rows of Stock Transfer and Stock Adjustment, so it could not create a transfer nor hand
 *    these menus to another role. It gets them now.
 *
 * Billing (plans, coupons, tenants, subscription invoices) stays superadmin-only: it is platform-level.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: string, 2: string}>}>
     */
    private const PAGES = [
        '/stocktracking' => ['Serials & Batches', 'ScanBarcode', 'stocktracking', [
            ['Register Serials / Batches', 'stocktracking.add', '/stocktracking/add'],
            ['Write Off Serials / Batches', 'stocktracking.edit', '/stocktracking/edit'],
        ]],
        '/warehouselocation' => ['Warehouse Locations', 'LayoutGrid', 'warehouselocation', [
            ['Add Warehouse Location', 'warehouselocation.add', '/warehouselocation/add'],
            ['Edit Warehouse Location', 'warehouselocation.edit', '/warehouselocation/:id/edit'],
            ['Delete Warehouse Location', 'warehouselocation.delete', '/warehouselocation/delete'],
        ]],
    ];

    /**
     * @var list<string>
     */
    private const GRANTED_PATHS = [
        '/leads', '/leadsources', '/opportunities', '/pipeline', '/pipelinestages', '/activities', '/crmanalytics',
        '/stocktransfer/add', '/stocktransfer/:id/edit', '/stocktransfer/delete',
        '/stockadjustment/add', '/stockadjustment/:id/edit', '/stockadjustment/delete',
        '/stocktracking', '/warehouselocation',
    ];

    public function up(): void
    {
        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $anchor = DB::table('menus')->where('route_path', '/warehouse')->first();

        if ($anchor !== null) {
            foreach (self::PAGES as $path => [$name, $icon, $routeName, $hidden]) {
                if (DB::table('menus')->where('route_path', $path)->exists()) {
                    continue;
                }

                $pageId = DB::table('menus')->insertGetId($this->row([
                    'parent_id' => $anchor->parent_id, 'name' => $name, 'icon' => $icon, 'route_name' => $routeName, 'route_path' => $path,
                    'sort_order' => (int) DB::table('menus')->where('parent_id', $anchor->parent_id)->max('sort_order') + 1, 'is_hidden' => 0,
                ], $hasAdminColumn));

                foreach ($hidden as $index => [$childName, $childRouteName, $childPath]) {
                    DB::table('menus')->insert($this->row([
                        'parent_id' => $pageId, 'name' => $childName, 'icon' => 'Grid', 'route_name' => $childRouteName, 'route_path' => $childPath,
                        'sort_order' => $index + 1, 'is_hidden' => 1,
                    ], $hasAdminColumn));
                }
            }
        }

        $roleId = $this->companyAdminRoleId();

        if ($roleId !== null) {
            foreach ($this->menuIds() as $menuId) {
                DB::table('permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'menu_id' => $menuId, 'company_id' => null, 'branch_id' => null, 'department_id' => null],
                    ['status' => 1, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $roleId = $this->companyAdminRoleId();

        if ($roleId !== null) {
            DB::table('permissions')->where('role_id', $roleId)->whereIn('menu_id', $this->menuIds())->delete();
        }

        $paths = [];

        foreach (self::PAGES as $path => [, , , $hidden]) {
            $paths = array_merge($paths, [$path], array_column($hidden, 2));
        }

        $ids = DB::table('menus')->whereIn('route_path', $paths)->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
    }

    /**
     * @return list<int>
     */
    private function menuIds(): array
    {
        $ids = [];

        foreach (DB::table('menus')->whereNotNull('route_path')->pluck('route_path', 'id') as $id => $path) {
            foreach (self::GRANTED_PATHS as $granted) {
                if ($path === $granted || str_starts_with((string) $path, $granted.'/')) {
                    $ids[] = (int) $id;

                    break;
                }
            }
        }

        return $ids;
    }

    private function companyAdminRoleId(): ?int
    {
        $id = DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->whereNull('deleted_at')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function row(array $row, bool $hasAdminColumn): array
    {
        $row += ['type' => 1, 'menu_color' => '#199683', 'is_active' => 1, 'is_permission' => 1, 'created_at' => now(), 'updated_at' => now()];

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
