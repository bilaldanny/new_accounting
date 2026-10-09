<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead import is gated on the `/leads/import` menu permission (frontend button + LeadController), but
 * the Step 1 menu migration didn't carry that path yet. Mirrors
 * `2026_09_19_060612_add_product_import_export_trash_menus.php`'s "add one more hidden child to an
 * already-existing page" pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        $leadsId = DB::table('menus')->where('route_path', '/leads')->value('id');

        if ($leadsId === null) {
            return;
        }

        if (DB::table('menus')->where('parent_id', $leadsId)->where('route_path', '/leads/import')->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        $payload = [
            'parent_id' => $leadsId,
            'name' => 'Lead Import',
            'icon' => 'Grid',
            'route_name' => 'leads.import',
            'route_path' => '/leads/import',
            'menu_color' => '#199683',
            'sort_order' => (int) DB::table('menus')->where('parent_id', $leadsId)->max('sort_order') + 1,
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

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->where('route_path', '/leads/import')->pluck('id');

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
