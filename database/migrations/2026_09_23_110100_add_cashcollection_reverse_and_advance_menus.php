<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two hidden permission rows under the Cash Collection page: Reverse (undo a completed collection) and
 * Advance (settle invoices out of the advance a collection kept). They come after
 * 2026_09_21_260100_add_cashcollection_menu, so this does nothing without the Cash Collection page.
 * Permissions are not granted here (see the companyadmin grant migration that follows).
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const ROWS = [
        ['Reverse Cash Collection', 'cashcollection.reverse', '/cashcollection/reverse'],
        ['Use Cash Collection Advance', 'cashcollection.advance', '/cashcollection/advance'],
    ];

    public function up(): void
    {
        $pageId = DB::table('menus')->where('route_path', '/cashcollection')->value('id');

        if ($pageId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        foreach (self::ROWS as [$name, $routeName, $path]) {
            if (DB::table('menus')->where('route_path', $path)->exists()) {
                continue;
            }

            $row = [
                'parent_id' => $pageId,
                'name' => $name,
                'icon' => 'Grid',
                'route_name' => $routeName,
                'route_path' => $path,
                'sort_order' => (int) DB::table('menus')->where('parent_id', $pageId)->max('sort_order') + 1,
                'is_hidden' => 1,
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

            DB::table('menus')->insert($row);
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->whereIn('route_path', array_column(self::ROWS, 2))->pluck('id');

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
