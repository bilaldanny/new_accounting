<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Bank Reconciliation page next to the Chart of Accounts page (same parent group, found through the
 * `/chart-of-account` anchor row; skipped when it is missing), with hidden permission rows for add, match,
 * reconcile and delete, and grants all five to the global companyadmin role. Other roles are granted from the
 * Role Permission screen.
 */
return new class extends Migration
{
    private const PATH = '/bankreconciliation';

    private const HIDDEN = [
        ['Add Bank Statement', 'bankreconciliation.add', '/bankreconciliation/add'],
        ['Match Bank Statement Lines', 'bankreconciliation.match', '/bankreconciliation/match'],
        ['Reconcile Bank Statement', 'bankreconciliation.reconcile', '/bankreconciliation/reconcile'],
        ['Delete Bank Statement', 'bankreconciliation.delete', '/bankreconciliation/delete'],
    ];

    public function up(): void
    {
        if (DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $groupId = DB::table('menus')->where('route_path', '/chart-of-account')->value('parent_id');

        if ($groupId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        $pageId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $groupId,
            'name' => 'Bank Reconciliation',
            'icon' => 'Landmark',
            'route_name' => 'bankreconciliation',
            'route_path' => self::PATH,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
            'is_hidden' => 0,
        ], $hasAdminColumn));

        $ids = [$pageId];

        foreach (self::HIDDEN as $index => [$name, $routeName, $path]) {
            $ids[] = DB::table('menus')->insertGetId($this->row([
                'parent_id' => $pageId,
                'name' => $name,
                'icon' => 'Grid',
                'route_name' => $routeName,
                'route_path' => $path,
                'sort_order' => $index + 1,
                'is_hidden' => 1,
            ], $hasAdminColumn));
        }

        $this->grantCompanyAdmin($ids);
        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $paths = array_merge([self::PATH], array_column(self::HIDDEN, 2));
        $ids = DB::table('menus')->whereIn('route_path', $paths)->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
    }

    /**
     * @param  list<int>  $menuIds
     */
    private function grantCompanyAdmin(array $menuIds): void
    {
        $roleId = DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->whereNull('deleted_at')->orderBy('id')->value('id');

        if ($roleId === null) {
            return;
        }

        DB::table('permissions')->insert(array_map(fn (int $menuId): array => [
            'company_id' => null, 'branch_id' => null, 'department_id' => null,
            'role_id' => $roleId, 'menu_id' => $menuId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $menuIds));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function row(array $row, bool $hasAdminColumn): array
    {
        $row += [
            'type' => 1,
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
