<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The hidden "Opening Stock" rows point at `/openingstock` and `/openingstock/add`, which have no
 * route, controller or page. Opening stock is entered through Stock Adjustment (type "Opening"), so
 * the rows are soft-deleted. Permission rows are left untouched so `down()` restores them exactly.
 */
return new class extends Migration
{
    private const PATHS = ['/openingstock', '/openingstock/add'];

    public function up(): void
    {
        DB::table('menus')
            ->whereIn('route_path', self::PATHS)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        DB::table('menus')
            ->whereIn('route_path', self::PATHS)
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
