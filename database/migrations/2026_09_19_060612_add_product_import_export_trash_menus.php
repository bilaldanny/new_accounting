<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product import, export, trash and restore are gated on `permission_paths` (frontend buttons) and
 * `hasMenuPermission()` (ProductController), but no menu row carried those paths, so only superadmin
 * could use them. Mirrors the hidden Unit Export/Import/Trash rows, plus Restore, which both the
 * controller and the Trash page check.
 */
return new class extends Migration
{
    /**
     * @var list<array{name: string, route_name: string, route_path: string}>
     */
    private const ROWS = [
        ['name' => 'Product Export', 'route_name' => 'product.export', 'route_path' => '/product/export'],
        ['name' => 'Product Import', 'route_name' => 'product.import', 'route_path' => '/product/import'],
        ['name' => 'Product Trash', 'route_name' => 'product.trash', 'route_path' => '/product/trash'],
        ['name' => 'Product Restore', 'route_name' => 'product.restore', 'route_path' => '/product/restore'],
    ];

    public function up(): void
    {
        $productId = DB::table('menus')->where('route_path', '/product')->value('id');

        if ($productId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        foreach (self::ROWS as $row) {
            $exists = DB::table('menus')
                ->where('parent_id', $productId)
                ->where('route_path', $row['route_path'])
                ->exists();

            if ($exists) {
                continue;
            }

            $payload = [
                'parent_id' => $productId,
                'name' => $row['name'],
                'icon' => '',
                'route_name' => $row['route_name'],
                'route_path' => $row['route_path'],
                'menu_color' => '#6a0dad',
                'sort_order' => 1,
                'is_hidden' => 1,
                'is_active' => 1,
                'is_permission' => 1,
                'type' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($hasAdminColumn) {
                $payload['is_admin'] = 0;
            }

            DB::table('menus')->insert($payload);
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $productId = DB::table('menus')->where('route_path', '/product')->value('id');

        if ($productId !== null) {
            $ids = DB::table('menus')
                ->where('parent_id', $productId)
                ->whereIn('route_path', array_column(self::ROWS, 'route_path'))
                ->pluck('id');

            DB::table('permissions')->whereIn('menu_id', $ids)->delete();
            DB::table('menus')->whereIn('id', $ids)->delete();
        }

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
