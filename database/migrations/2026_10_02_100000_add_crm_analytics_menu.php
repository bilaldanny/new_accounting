<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the CRM Analytics page under the CRM group created by `2026_10_01_100100_add_crm_leads_menu.php`.
 * A single read-only report page (no add/edit/delete), so unlike the other CRM pages it has no hidden
 * permission children — just the one `/crmanalytics` permission the controller checks on every
 * endpoint. Does nothing if the CRM group is missing (same guard the other CRM menu migrations use).
 *
 * Permissions are not granted here: roles get the row from the Role Permission screen.
 */
return new class extends Migration
{
    private const PATH = '/crmanalytics';

    public function up(): void
    {
        if (DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $groupId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'CRM')
            ->where('type', 2)
            ->value('id');

        if ($groupId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        $row = [
            'parent_id' => $groupId,
            'name' => 'CRM Analytics',
            'icon' => 'ChartNoAxesCombined',
            'route_name' => 'crmanalytics',
            'route_path' => self::PATH,
            'menu_color' => '#199683',
            'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
            'is_hidden' => 0,
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
