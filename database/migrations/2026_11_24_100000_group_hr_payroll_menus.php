<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The HR and Payroll menu was one row inside the User Management group (with the Setup Wizard, Help Center and a dozen others), and its
 * attendance / payroll / setup rows were hidden permission rows, so the module was easy to miss. It becomes a group of its own in the
 * sidebar, with Employees, Attendance and Leave, Payroll and HR Setup as visible entries (each opens the HR hub on that tab).
 *
 * Only the position, the names and the hidden flag of existing rows change; no row is created apart from the group, no permission is
 * touched (the group is shown to whoever holds one of its entries, as the sidebar always does for parents).
 */
return new class extends Migration
{
    private const GROUP_NAME = 'HR and Payroll';

    public function up(): void
    {
        $hub = DB::table('menus')->where('route_path', '/hrm')->first();

        if ($hub === null) {
            return;
        }

        $group = DB::table('menus')->where('type', 2)->where('name', self::GROUP_NAME)->first();
        $groupId = $group?->id;

        if ($groupId === null) {
            $after = DB::table('menus')->whereNull('parent_id')->where('name', 'User Management')->value('sort_order');
            // a group has no route of its own; the live column is nullable, one built only from migrations is NOT NULL
            $nullable = (bool) (collect(Schema::getColumns('menus'))->firstWhere('name', 'route_path')['nullable'] ?? false);
            $groupId = DB::table('menus')->insertGetId([
                'parent_id' => null, 'name' => self::GROUP_NAME, 'icon' => 'Users', 'route_name' => '', 'route_path' => $nullable ? null : '', 'menu_color' => '#199683',
                'sort_order' => (int) ($after ?? 0) + 1, 'is_hidden' => 0, 'is_active' => 1, 'is_permission' => 0, 'type' => 2, 'is_admin' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $entries = [
            '/hrm' => ['Employees', 1],
            '/hrm/attendance' => ['Attendance and Leave', 2],
            '/hrm/payroll' => ['Payroll', 3],
            '/hrm/manage' => ['HR Setup', 4],
        ];

        foreach ($entries as $path => [$name, $order]) {
            DB::table('menus')->where('route_path', $path)->update(['parent_id' => $groupId, 'name' => $name, 'is_hidden' => 0, 'sort_order' => $order, 'updated_at' => now()]);
        }

        self::flushCaches();
    }

    public function down(): void
    {
        $hub = DB::table('menus')->where('route_path', '/hrm')->first();
        $group = DB::table('menus')->where('type', 2)->where('name', self::GROUP_NAME)->first();

        if ($hub === null || $group === null) {
            return;
        }

        $users = DB::table('menus')->whereNull('parent_id')->where('name', 'User Management')->value('id');
        $names = ['/hrm' => 'HR and Payroll', '/hrm/attendance' => 'Attendance and Leave', '/hrm/payroll' => 'Prepare Payroll', '/hrm/manage' => 'Manage Employees and Settings'];

        foreach ($names as $path => $name) {
            DB::table('menus')->where('route_path', $path)->update([
                'parent_id' => $path === '/hrm' ? $users : $hub->id, 'name' => $name, 'is_hidden' => $path === '/hrm' ? 0 : 1, 'updated_at' => now(),
            ]);
        }

        DB::table('menus')->where('id', $group->id)->delete();
        self::flushCaches();
    }

    private static function flushCaches(): void
    {
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
                Cache::forget("{$key}:{$roleId}");
            }
        }
    }
};
