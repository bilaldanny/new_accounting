<?php

namespace App\Support;

use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The menu rows a database needs before any role can be given a page. The original menus came from the live database's dump, not from
 * a migration, so an install that starts from migrations has only the menus added since. This inserts a row for every menu path the
 * code checks or serves that has none yet (database/data/baseline_menus.php), under the nearest existing parent path, and gives the
 * shared `companyadmin` role every company-level menu.
 *
 * It only ever adds: a path that already has a row (the dump's polished names, icons and groups) is never touched.
 */
final class BaselineMenus
{
    /** Paths that belong to the platform operator, not to a company admin. */
    private const PLATFORM_PATHS = '#^/(subscriptionplans|tenants|subscriptioninvoices|subscriptionbilling|saasadmin|software/setting|company/add|company/delete|company/restore)#';

    /**
     * @return array{added: int, granted: int, role_created: bool}
     */
    public static function ensure(bool $dryRun = false): array
    {
        /** @var array<string, array{name: string, hidden: bool}> $baseline */
        $baseline = require database_path('data/baseline_menus.php');
        ksort($baseline);

        $existing = DB::table('menus')->whereNotNull('route_path')->pluck('id', 'route_path')->all();
        $added = 0;

        foreach ($baseline as $path => $row) {
            if (isset($existing[$path])) {
                continue;
            }

            $added++;

            if ($dryRun) {
                continue;
            }

            $parentId = null;

            for ($parent = $path; ($cut = strrpos($parent, '/')) > 0;) {
                $parent = substr($parent, 0, $cut);

                if (isset($existing[$parent])) {
                    $parentId = (int) $existing[$parent];

                    break;
                }
            }

            $existing[$path] = DB::table('menus')->insertGetId([
                'parent_id' => $parentId, 'name' => $row['name'], 'icon' => 'Grid', 'route_name' => trim(str_replace(['/', ':id'], ['.', ''], $path), '.'), 'route_path' => $path,
                'menu_color' => '#199683', 'sort_order' => 100 + $added, 'is_hidden' => $row['hidden'] ? 1 : 0, 'is_active' => 1, 'is_permission' => 1, 'type' => 1,
                'is_admin' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if ($dryRun) {
            return ['added' => $added, 'granted' => 0, 'role_created' => false];
        }

        [$granted, $created] = self::grantCompanyAdmin();

        return ['added' => $added, 'granted' => $granted, 'role_created' => $created];
    }

    /**
     * @return array{0: int, 1: bool} menus newly granted, role created
     */
    private static function grantCompanyAdmin(): array
    {
        $role = Role::query()->whereNull('company_id')->whereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [Role::normalizeName(Role::HIDDEN_ROLE_NAME)])->orderBy('id')->first();
        $created = $role === null;
        $role ??= Role::query()->create(['name' => Role::HIDDEN_ROLE_NAME, 'company_id' => null, 'is_active' => true, 'is_admin' => false]);

        $has = DB::table('permissions')->where('role_id', $role->id)->pluck('menu_id')->flip();
        $granted = 0;

        foreach (DB::table('menus')->where('is_active', 1)->where('is_admin', 0)->get(['id', 'route_path']) as $menu) {
            if (isset($has[$menu->id]) || ($menu->route_path !== null && preg_match(self::PLATFORM_PATHS, (string) $menu->route_path) === 1)) {
                continue;
            }

            DB::table('permissions')->insert([
                'role_id' => $role->id, 'menu_id' => $menu->id, 'company_id' => null, 'branch_id' => null, 'department_id' => null, 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $granted++;
        }

        foreach (DB::table('roles')->pluck('id') as $roleId) {
            foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
                Cache::forget("{$key}:{$roleId}");
            }
        }

        return [$granted, $created];
    }
}
