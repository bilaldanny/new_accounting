<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the hidden permission rows for acquiring an asset, adding construction costs and capitalising, under the
 * Fixed Assets page (found through its `/fixedasset` row; skipped when it is missing), and grants them to the global
 * companyadmin role. Other roles are granted from the Role Permission screen.
 */
return new class extends Migration
{
    private const PAGE = '/fixedasset';

    private const HIDDEN = [
        ['Acquire Fixed Asset', 'fixedasset.acquire', '/fixedasset/acquire'],
        ['Add Construction Cost', 'fixedasset.cwip', '/fixedasset/cwip'],
        ['Capitalise Fixed Asset', 'fixedasset.capitalise', '/fixedasset/capitalise'],
    ];

    public function up(): void
    {
        $pageId = DB::table('menus')->where('route_path', self::PAGE)->value('id');

        if ($pageId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $ids = [];

        foreach (self::HIDDEN as [$name, $routeName, $path]) {
            if (DB::table('menus')->where('route_path', $path)->exists()) {
                continue;
            }

            $row = [
                'parent_id' => $pageId, 'name' => $name, 'icon' => 'Grid', 'route_name' => $routeName, 'route_path' => $path,
                'menu_color' => '#199683', 'sort_order' => (int) DB::table('menus')->where('parent_id', $pageId)->max('sort_order') + 1,
                'is_hidden' => 1, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
            ];

            if ($hasAdminColumn) {
                $row['is_admin'] = 0;
            }

            $ids[] = DB::table('menus')->insertGetId($row);
        }

        $this->grantCompanyAdmin($ids);
        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->whereIn('route_path', array_column(self::HIDDEN, 2))->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
    }

    /**
     * @param  list<int>  $menuIds
     */
    private function grantCompanyAdmin(array $menuIds): void
    {
        $roleId = DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->whereNull('deleted_at')->orderBy('id')->value('id');

        if ($roleId === null || $menuIds === []) {
            return;
        }

        DB::table('permissions')->insert(array_map(fn (int $menuId): array => [
            'company_id' => null, 'branch_id' => null, 'department_id' => null,
            'role_id' => $roleId, 'menu_id' => $menuId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $menuIds));
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
