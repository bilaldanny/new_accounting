<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What every "add this page" migration does: insert the menu rows under an existing parent (found by its route path),
 * grant them to the global companyadmin role, and forget the cached menu trees. A row whose path already exists, or
 * whose parent is missing, is skipped, so running a migration twice or on a database without the group is harmless.
 *
 * Each row is [parent route_path, name, route_path, route_name, icon, is_hidden]. The parent is a route path, or
 * `sibling:/page` for "in the same group as that page". Rows are inserted in order, so a child can name a page created
 * earlier in the same list.
 */
final class MenuMigration
{
    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>  $rows
     */
    public static function install(array $rows): void
    {
        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $roleId = DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->whereNull('deleted_at')->orderBy('id')->value('id');

        foreach ($rows as [$parentPath, $name, $path, $routeName, $icon, $hidden]) {
            $parentId = str_starts_with($parentPath, 'sibling:')
                ? DB::table('menus')->where('route_path', substr($parentPath, 8))->value('parent_id')
                : DB::table('menus')->where('route_path', $parentPath)->value('id');

            if ($parentId === null) {
                continue;
            }

            $existingId = DB::table('menus')->where('route_path', $path)->value('id');

            if ($existingId === null) {
                $row = [
                    'parent_id' => $parentId, 'name' => $name, 'icon' => $icon, 'route_name' => $routeName, 'route_path' => $path,
                    'menu_color' => '#199683', 'sort_order' => (int) DB::table('menus')->where('parent_id', $parentId)->max('sort_order') + 1,
                    'is_hidden' => $hidden ? 1 : 0, 'is_active' => 1, 'is_permission' => 1, 'type' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ];

                if ($hasAdminColumn) {
                    $row['is_admin'] = 0;
                }

                $existingId = DB::table('menus')->insertGetId($row);
            }

            if ($roleId !== null && ! DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $existingId)->exists()) {
                DB::table('permissions')->insert([
                    'company_id' => null, 'branch_id' => null, 'department_id' => null,
                    'role_id' => $roleId, 'menu_id' => $existingId, 'status' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        self::flushCaches();
    }

    /**
     * @param  list<string>  $paths
     */
    public static function remove(array $paths): void
    {
        $ids = DB::table('menus')->whereIn('route_path', $paths)->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        self::flushCaches();
    }

    /**
     * The four rows a page with add, edit and delete has: the page, then its hidden add / edit / delete permissions.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    public static function crudPage(string $parentPath, string $title, string $path, string $icon = 'Grid', bool $hidden = false): array
    {
        $routeName = ltrim($path, '/');

        return [
            [$parentPath, $title, $path, $routeName, $icon, $hidden],
            [$path, "Add {$title}", "{$path}/add", "{$routeName}.add", 'Grid', true],
            [$path, "Edit {$title}", "{$path}/:id/edit", "{$routeName}.edit", 'Grid', true],
            [$path, "Delete {$title}", "{$path}/delete", "{$routeName}.delete", 'Grid', true],
        ];
    }

    private static function flushCaches(): void
    {
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            Cache::forget("user_menu_permissions_tree:{$roleId}");
            Cache::forget("user_permission_paths:{$roleId}");
            Cache::forget("user_menu_permissions:{$roleId}");
        }
    }
}
