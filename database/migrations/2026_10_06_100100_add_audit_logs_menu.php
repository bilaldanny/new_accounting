<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the read-only Activity Log page under the User Management group. Its own path is the permission
 * AuditLogController checks. Does nothing if the group is missing. Permissions are not granted here.
 */
return new class extends Migration
{
    private const PATH = '/auditlogs';

    public function up(): void
    {
        if (DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'User Management')->where('type', 2)->value('id');

        if ($groupId === null) {
            return;
        }

        $row = [
            'parent_id' => $groupId,
            'name' => 'Activity Log',
            'icon' => 'ScrollText',
            'route_name' => 'auditlogs',
            'route_path' => self::PATH,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
            'is_hidden' => 0,
            'type' => 1,
            'menu_color' => '#6a0dad',
            'is_active' => 1,
            'is_permission' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('menus', 'is_admin')) {
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
