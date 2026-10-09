<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Activities page under the CRM group created by `2026_10_01_100100_add_crm_leads_menu.php`,
 * with the hidden permission rows its controller and pages check: add, edit, delete, view, restore
 * (from the trash page), export and complete. Does nothing if the CRM group is missing (same guard the
 * other CRM menu migrations use).
 *
 * Permissions are not granted here: roles get the rows from the Role Permission screen.
 */
return new class extends Migration
{
    private const PATH = '/activities';

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

        $pageId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $groupId,
            'name' => 'Activities',
            'icon' => 'CalendarCheck',
            'route_name' => 'activities',
            'route_path' => self::PATH,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
            'is_hidden' => 0,
            'type' => 1,
        ], $hasAdminColumn));

        $children = [
            ['Add Activity', 'activities.add', '/activities/add'],
            ['Edit Activity', 'activities.edit', '/activities/:id/edit'],
            ['Delete Activity', 'activities.delete', '/activities/delete'],
            ['View Activity', 'activities.view', '/activities/:id/view'],
            ['Restore Activity', 'activities.restore', '/activities/restore'],
            ['Activity Export', 'activities.export', '/activities/export'],
            ['Complete Activity', 'activities.complete', '/activities/:id/complete'],
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
                'type' => 1,
            ], $hasAdminColumn));
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->where('route_path', self::PATH)->orWhere('route_path', 'like', self::PATH.'/%')->pluck('id');

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
