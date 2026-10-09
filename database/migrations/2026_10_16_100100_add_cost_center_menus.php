<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Cost Centers page next to Journal Entries (the same parent group, found through the `/journalentry` anchor
 * row) with hidden add / edit / delete permission rows, and the Cost Center Analysis report next to the Stock report
 * (the `/report/stock` anchor). Each part is skipped when its anchor is missing. All of it is granted to the global
 * companyadmin role; other roles are granted from the Role Permission screen.
 */
return new class extends Migration
{
    private const PAGE = '/costcenter';

    private const REPORT = '/report/cost-center-analysis';

    private const HIDDEN = [
        ['Add Cost Center', 'costcenter.add', '/costcenter/add'],
        ['Edit Cost Center', 'costcenter.edit', '/costcenter/:id/edit'],
        ['Delete Cost Center', 'costcenter.delete', '/costcenter/delete'],
    ];

    public function up(): void
    {
        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $ids = [];

        $accountsGroup = DB::table('menus')->where('route_path', '/journalentry')->value('parent_id');

        if ($accountsGroup !== null && ! DB::table('menus')->where('route_path', self::PAGE)->exists()) {
            $pageId = DB::table('menus')->insertGetId($this->row([
                'parent_id' => $accountsGroup, 'name' => 'Cost Centers', 'icon' => 'Network', 'route_name' => 'costcenter', 'route_path' => self::PAGE,
                'sort_order' => (int) DB::table('menus')->where('parent_id', $accountsGroup)->max('sort_order') + 1, 'is_hidden' => 0,
            ], $hasAdminColumn));
            $ids[] = $pageId;

            foreach (self::HIDDEN as $index => [$name, $routeName, $path]) {
                $ids[] = DB::table('menus')->insertGetId($this->row([
                    'parent_id' => $pageId, 'name' => $name, 'icon' => 'Grid', 'route_name' => $routeName, 'route_path' => $path,
                    'sort_order' => $index + 1, 'is_hidden' => 1,
                ], $hasAdminColumn));
            }
        }

        $reportsGroup = DB::table('menus')->where('route_path', '/report/stock')->value('parent_id');

        if ($reportsGroup !== null && ! DB::table('menus')->where('route_path', self::REPORT)->exists()) {
            $ids[] = DB::table('menus')->insertGetId($this->row([
                'parent_id' => $reportsGroup, 'name' => 'Cost Center & Department Analysis', 'icon' => 'FileBarChart', 'route_name' => 'report.cost-center-analysis', 'route_path' => self::REPORT,
                'sort_order' => (int) DB::table('menus')->where('parent_id', $reportsGroup)->max('sort_order') + 1, 'is_hidden' => 0,
            ], $hasAdminColumn));
        }

        $roleId = DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->whereNull('deleted_at')->orderBy('id')->value('id');

        if ($roleId !== null && $ids !== []) {
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
        $paths = array_merge([self::PAGE, self::REPORT], array_column(self::HIDDEN, 2));
        $ids = DB::table('menus')->whereIn('route_path', $paths)->pluck('id');

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
