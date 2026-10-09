<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Budgets page next to Cost Centers (the same parent group, found through the `/costcenter` row; skipped when
 * it is missing) with hidden add / edit / delete / restore permission rows, and grants them to the global companyadmin
 * role. Other roles are granted from the Role Permission screen.
 */
return new class extends Migration
{
    private const PAGE = '/budget';

    private const HIDDEN = [
        ['Add Budget', 'budget.add', '/budget/add'],
        ['Edit Budget', 'budget.edit', '/budget/:id/edit'],
        ['Delete Budget', 'budget.delete', '/budget/delete'],
        ['Restore Budget', 'budget.restore', '/budget/restore'],
    ];

    public function up(): void
    {
        $groupId = DB::table('menus')->where('route_path', '/costcenter')->value('parent_id');

        if ($groupId === null || DB::table('menus')->where('route_path', self::PAGE)->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $pageId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $groupId, 'name' => 'Budgets', 'icon' => 'PiggyBank', 'route_name' => 'budget', 'route_path' => self::PAGE,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1, 'is_hidden' => 0,
        ], $hasAdminColumn));
        $ids = [$pageId];

        foreach (self::HIDDEN as $index => [$name, $routeName, $path]) {
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
        $ids = DB::table('menus')->whereIn('route_path', array_merge([self::PAGE], array_column(self::HIDDEN, 2)))->pluck('id');

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
