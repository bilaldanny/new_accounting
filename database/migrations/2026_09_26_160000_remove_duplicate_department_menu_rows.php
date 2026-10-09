<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes the duplicate "Department Export/Import/Trash" menu rows (ids 159-161 in live data),
 * left over from the 2026-08 bulk import. There are two sets of the same three route paths:
 *
 * - `/department/export`, `/department/import`, `/department/trash` parented under the "Menus" group
 *   (route_path `/menu`) — the wrong parent for a Department action row. No `permissions` row anywhere
 *   references them (confirmed by SQL: 0 rows for menu_id in (159,160,161) on live data).
 * - the same three route paths, correctly parented under "Department" (route_path `/department`), and
 *   the ones actually granted to companyadmin.
 *
 * The wrongly-parented set is dead. This resolves both parents by route_path rather than a hardcoded id
 * (ids differ between environments), soft-deletes only the rows under the "Menus" parent, and leaves the
 * live set and every `permissions` row untouched, following the same convention as
 * 2026_09_19_060918_drop_dead_opening_stock_menus.php so `down()` can restore exactly what it removed.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const PATHS = ['/department/export', '/department/import', '/department/trash'];

    public function up(): void
    {
        $deadParentId = DB::table('menus')->where('route_path', '/menu')->value('id');
        $liveParentId = DB::table('menus')->where('route_path', '/department')->value('id');

        if ($deadParentId === null || $deadParentId === $liveParentId) {
            return;
        }

        DB::table('menus')
            ->whereIn('route_path', self::PATHS)
            ->where('parent_id', $deadParentId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $deadParentId = DB::table('menus')->where('route_path', '/menu')->value('id');

        if ($deadParentId === null) {
            return;
        }

        DB::table('menus')
            ->whereIn('route_path', self::PATHS)
            ->where('parent_id', $deadParentId)
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null, 'updated_at' => now()]);

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
