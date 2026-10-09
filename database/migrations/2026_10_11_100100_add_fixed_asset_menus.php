<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds Asset Categories and Fixed Assets next to Journal Entries (the same parent group, found through the
 * `/journalentry` anchor row; skipped when it is missing), each with hidden add / edit / delete permission rows,
 * and grants them to the global companyadmin role. Other roles are granted from the Role Permission screen.
 */
return new class extends Migration
{
    /**
     * Page path => [name, icon, route name, hidden rows [name, route name, path]].
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: string, 2: string}>}>
     */
    private function pages(): array
    {
        return [
            '/assetcategory' => ['Asset Categories', 'Layers', 'assetcategory', [
                ['Add Asset Category', 'assetcategory.add', '/assetcategory/add'],
                ['Edit Asset Category', 'assetcategory.edit', '/assetcategory/:id/edit'],
                ['Delete Asset Category', 'assetcategory.delete', '/assetcategory/delete'],
            ]],
            '/fixedasset' => ['Fixed Assets', 'Building', 'fixedasset', [
                ['Add Fixed Asset', 'fixedasset.add', '/fixedasset/add'],
                ['Edit Fixed Asset', 'fixedasset.edit', '/fixedasset/:id/edit'],
                ['Delete Fixed Asset', 'fixedasset.delete', '/fixedasset/delete'],
            ]],
        ];
    }

    public function up(): void
    {
        $groupId = DB::table('menus')->where('route_path', '/journalentry')->value('parent_id');

        if ($groupId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $ids = [];

        foreach ($this->pages() as $path => [$name, $icon, $routeName, $hidden]) {
            if (DB::table('menus')->where('route_path', $path)->exists()) {
                continue;
            }

            $pageId = DB::table('menus')->insertGetId($this->row([
                'parent_id' => $groupId,
                'name' => $name,
                'icon' => $icon,
                'route_name' => $routeName,
                'route_path' => $path,
                'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
                'is_hidden' => 0,
            ], $hasAdminColumn));

            $ids[] = $pageId;

            foreach ($hidden as $index => [$hiddenName, $hiddenRoute, $hiddenPath]) {
                $ids[] = DB::table('menus')->insertGetId($this->row([
                    'parent_id' => $pageId,
                    'name' => $hiddenName,
                    'icon' => 'Grid',
                    'route_name' => $hiddenRoute,
                    'route_path' => $hiddenPath,
                    'sort_order' => $index + 1,
                    'is_hidden' => 1,
                ], $hasAdminColumn));
            }
        }

        $this->grantCompanyAdmin($ids);
        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $paths = [];

        foreach ($this->pages() as $path => $page) {
            $paths[] = $path;

            foreach ($page[3] as $hidden) {
                $paths[] = $hidden[2];
            }
        }

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

        if ($roleId === null || $menuIds === []) {
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
