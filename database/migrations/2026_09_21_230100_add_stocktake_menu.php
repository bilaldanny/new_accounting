<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Stock Take page in the Products group, with the hidden permission rows its controller and pages
 * check: add, view, count, complete, delete, restore, export.
 *
 * The Products group is a legacy row that only exists in live data, so it is found through the Products
 * page (`/product`) and the migration does nothing without it. Permissions are not granted here: roles
 * get the rows from the Role Permission screen (see the companyadmin grant migration for the default role).
 */
return new class extends Migration
{
    private const PATH = '/stocktake';

    public function up(): void
    {
        if (DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $groupId = DB::table('menus')->where('route_path', '/product')->value('parent_id');

        if ($groupId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        $pageId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $groupId,
            'name' => 'Stock Take',
            'icon' => 'Layers',
            'route_name' => 'stocktake',
            'route_path' => self::PATH,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
            'is_hidden' => 0,
        ], $hasAdminColumn));

        $children = [
            ['Add Stock Take', 'stocktake.add', '/stocktake/add'],
            ['View Stock Take', 'stocktake.view', '/stocktake/:id/view'],
            ['Count Stock Take', 'stocktake.count', '/stocktake/count'],
            ['Complete Stock Take', 'stocktake.complete', '/stocktake/complete'],
            ['Delete Stock Take', 'stocktake.delete', '/stocktake/delete'],
            ['Restore Stock Take', 'stocktake.restore', '/stocktake/restore'],
            ['Stock Take Export', 'stocktake.export', '/stocktake/export'],
        ];

        foreach ($children as $index => [$name, $routeName, $path]) {
            DB::table('menus')->insert($this->row([
                'parent_id' => $pageId,
                'name' => $name,
                'icon' => 'Grid',
                'route_name' => $routeName,
                'route_path' => $path,
                'sort_order' => $index + 1,
                'is_hidden' => 1,
            ], $hasAdminColumn));
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->where('route_path', 'like', self::PATH.'%')->pluck('id');

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
            'menu_color' => '#199683',
            'is_active' => 1,
            'is_permission' => 1,
            'type' => 1,
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
