<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Low Stock report under the Products group (the group that holds `/product`), plus the
 * hidden `/lowstock/export` permission that the table's Export buttons check.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route_path', '/lowstock')->exists()) {
            return;
        }

        $productsGroupId = DB::table('menus')->where('route_path', '/product')->value('parent_id');

        if ($productsGroupId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        $parent = [
            'parent_id' => $productsGroupId,
            'name' => 'Low Stock',
            'icon' => 'Tag',
            'route_name' => 'lowstock',
            'route_path' => '/lowstock',
            'menu_color' => '#199683',
            'sort_order' => (int) DB::table('menus')->where('parent_id', $productsGroupId)->max('sort_order') + 1,
            'is_hidden' => 0,
            'is_active' => 1,
            'is_permission' => 1,
            'type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $child = [
            'name' => 'Low Stock Export',
            'icon' => '',
            'route_name' => 'lowstock.export',
            'route_path' => '/lowstock/export',
            'menu_color' => '#199683',
            'sort_order' => 1,
            'is_hidden' => 1,
            'is_active' => 1,
            'is_permission' => 1,
            'type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($hasAdminColumn) {
            $parent['is_admin'] = 0;
            $child['is_admin'] = 0;
        }

        $child['parent_id'] = DB::table('menus')->insertGetId($parent);
        DB::table('menus')->insert($child);

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->whereIn('route_path', ['/lowstock', '/lowstock/export'])->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
    }

    private function flushMenuCaches(): void
    {
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            Cache::forget("user_menu_permissions_tree:{$roleId}");
            Cache::forget("user_permission_paths:{$roleId}");
        }
    }
};
