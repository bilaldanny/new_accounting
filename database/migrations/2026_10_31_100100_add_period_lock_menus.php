<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu rows of the period lock, next to the Financial Year row of Company Settings (found through the `/financialyear` anchor;
 * skipped when it is missing). The lock lives on the Financial Year tab, so the page row is hidden and only carries the
 * permission rows the controller checks by path:
 *
 * - /period-lock           see the lock and its history
 * - /period-lock/add       lock a period or move the lock forward (Accountant)
 * - /period-lock/reopen    move the lock back, clear it, change the dating rules (administrators)
 * - /period-lock/override  send one save through a locked period with a reason (administrators)
 * - /period-lock/rollover  preview and run the year rollover (Accountant)
 *
 * Granted to the global companyadmin role; the superadmin needs no row.
 */
return new class extends Migration
{
    private const PAGE = '/period-lock';

    private const CHILDREN = [
        ['Lock Period', 'periodlock.add', '/period-lock/add'],
        ['Reopen Period', 'periodlock.reopen', '/period-lock/reopen'],
        ['Override Period Lock', 'periodlock.override', '/period-lock/override'],
        ['Year Rollover', 'periodlock.rollover', '/period-lock/rollover'],
    ];

    public function up(): void
    {
        $parent = DB::table('menus')->where('route_path', '/financialyear')->value('parent_id');

        if ($parent === null || DB::table('menus')->where('route_path', self::PAGE)->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $ids = [];

        $pageId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $parent, 'name' => 'Period Lock', 'icon' => 'Lock', 'route_name' => 'periodlock', 'route_path' => self::PAGE,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $parent)->max('sort_order') + 1, 'is_hidden' => 1,
        ], $hasAdminColumn));
        $ids[] = $pageId;

        foreach (self::CHILDREN as $index => [$name, $routeName, $path]) {
            $ids[] = DB::table('menus')->insertGetId($this->row([
                'parent_id' => $pageId, 'name' => $name, 'icon' => 'Grid', 'route_name' => $routeName, 'route_path' => $path,
                'sort_order' => $index + 1, 'is_hidden' => 1,
            ], $hasAdminColumn));
        }

        $roleId = DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->whereNull('deleted_at')->orderBy('id')->value('id');

        if ($roleId !== null) {
            DB::table('permissions')->insert(array_map(fn (int $menuId): array => [
                'company_id' => null, 'branch_id' => null, 'department_id' => null,
                'role_id' => $roleId, 'menu_id' => $menuId, 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], $ids));
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->whereIn('route_path', array_merge([self::PAGE], array_column(self::CHILDREN, 2)))->pluck('id');

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
        $row += ['type' => 1, 'menu_color' => '#199683', 'is_active' => 1, 'is_permission' => 1, 'created_at' => now(), 'updated_at' => now()];

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
