<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Warehouse Stock report next to the Stock report (the same parent group, found through the `/report/stock`
 * anchor row; skipped when it is missing). Its own path is the permission its controller checks. It is granted to the
 * global companyadmin role; other roles are granted from the Role Permission screen.
 */
return new class extends Migration
{
    private const PATH = '/report/warehouse-stock';

    public function up(): void
    {
        $groupId = DB::table('menus')->where('route_path', '/report/stock')->value('parent_id');

        if ($groupId === null || DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $row = [
            'parent_id' => $groupId, 'name' => 'Warehouse Stock', 'icon' => 'Warehouse', 'route_name' => 'report.warehouse-stock', 'route_path' => self::PATH,
            'menu_color' => '#199683', 'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
            'is_hidden' => 0, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
        ];

        if (Schema::hasColumn('menus', 'is_admin')) {
            $row['is_admin'] = 0;
        }

        $menuId = DB::table('menus')->insertGetId($row);
        $roleId = DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->whereNull('deleted_at')->orderBy('id')->value('id');

        if ($roleId !== null) {
            DB::table('permissions')->insert([
                'company_id' => null, 'branch_id' => null, 'department_id' => null,
                'role_id' => $roleId, 'menu_id' => $menuId, 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->where('route_path', self::PATH)->pluck('id');

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
