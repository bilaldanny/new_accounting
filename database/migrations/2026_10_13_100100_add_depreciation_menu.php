<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Depreciation page next to Fixed Assets (the same parent group, found through the `/fixedasset` row; skipped
 * when it is missing) with a hidden permission row for running it, and grants both to the global companyadmin role.
 * Other roles are granted from the Role Permission screen (they need `/depreciation` to look and `/depreciation/run` to book).
 */
return new class extends Migration
{
    private const PATH = '/depreciation';

    private const RUN = '/depreciation/run';

    public function up(): void
    {
        if (DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $groupId = DB::table('menus')->where('route_path', '/fixedasset')->value('parent_id');

        if ($groupId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        $pageId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $groupId, 'name' => 'Depreciation', 'icon' => 'TrendingDown', 'route_name' => 'depreciation', 'route_path' => self::PATH,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1, 'is_hidden' => 0,
        ], $hasAdminColumn));

        $runId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $pageId, 'name' => 'Run Depreciation', 'icon' => 'Grid', 'route_name' => 'depreciation.run', 'route_path' => self::RUN,
            'sort_order' => 1, 'is_hidden' => 1,
        ], $hasAdminColumn));

        $roleId = DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->whereNull('deleted_at')->orderBy('id')->value('id');

        if ($roleId !== null) {
            DB::table('permissions')->insert(array_map(fn (int $menuId): array => [
                'company_id' => null, 'branch_id' => null, 'department_id' => null,
                'role_id' => $roleId, 'menu_id' => $menuId, 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], [$pageId, $runId]));
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->whereIn('route_path', [self::PATH, self::RUN])->pluck('id');

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
