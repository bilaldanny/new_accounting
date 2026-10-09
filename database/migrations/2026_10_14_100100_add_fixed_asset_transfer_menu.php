<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the hidden permission row for transferring an asset between branches, under the Fixed Assets page (found
 * through its `/fixedasset` row; skipped when it is missing), and grants it to the global companyadmin role. Other
 * roles are granted from the Role Permission screen.
 */
return new class extends Migration
{
    private const PATH = '/fixedasset/transfer';

    public function up(): void
    {
        $pageId = DB::table('menus')->where('route_path', '/fixedasset')->value('id');

        if ($pageId === null || DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $row = [
            'parent_id' => $pageId, 'name' => 'Transfer Fixed Asset', 'icon' => 'Grid', 'route_name' => 'fixedasset.transfer', 'route_path' => self::PATH,
            'menu_color' => '#199683', 'sort_order' => (int) DB::table('menus')->where('parent_id', $pageId)->max('sort_order') + 1,
            'is_hidden' => 1, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
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
