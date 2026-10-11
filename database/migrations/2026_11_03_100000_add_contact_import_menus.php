<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hidden permission rows for importing customers and suppliers from a spreadsheet (CustomerController::import and
 * SupplierController::import check them by path), under the existing Customer and Supplier pages. Granted to the
 * global companyadmin role; the superadmin needs no row. A page that is missing is skipped.
 */
return new class extends Migration
{
    private const CHILDREN = [
        ['/customer', 'Customer Import', 'customer.import', '/customer/import'],
        ['/supplier', 'Supplier Import', 'supplier.import', '/supplier/import'],
    ];

    public function up(): void
    {
        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $roleId = DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->whereNull('deleted_at')->orderBy('id')->value('id');

        foreach (self::CHILDREN as [$parentPath, $name, $routeName, $path]) {
            $parentId = DB::table('menus')->where('route_path', $parentPath)->value('id');

            if ($parentId === null || DB::table('menus')->where('route_path', $path)->exists()) {
                continue;
            }

            $row = [
                'parent_id' => $parentId, 'name' => $name, 'icon' => 'Grid', 'route_name' => $routeName, 'route_path' => $path,
                'menu_color' => '#199683', 'sort_order' => (int) DB::table('menus')->where('parent_id', $parentId)->max('sort_order') + 1,
                'is_hidden' => 1, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
            ];

            if ($hasAdminColumn) {
                $row['is_admin'] = 0;
            }

            $menuId = DB::table('menus')->insertGetId($row);

            if ($roleId !== null) {
                DB::table('permissions')->insert([
                    'company_id' => null, 'branch_id' => null, 'department_id' => null,
                    'role_id' => $roleId, 'menu_id' => $menuId, 'status' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->whereIn('route_path', array_column(self::CHILDREN, 3))->pluck('id');

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
