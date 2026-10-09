<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Tax Exemptions page next to Journal Entries (the same parent group, found through the `/journalentry` anchor row)
 * with hidden add / edit / delete permission rows. Skipped when the anchor is missing. Granted to the global companyadmin
 * role; other roles are granted from the Role Permission screen.
 */
return new class extends Migration
{
    private const PAGE = '/taxexemption';

    private const HIDDEN = [
        ['Add Tax Exemption', 'taxexemption.add', '/taxexemption/add'],
        ['Edit Tax Exemption', 'taxexemption.edit', '/taxexemption/:id/edit'],
        ['Delete Tax Exemption', 'taxexemption.delete', '/taxexemption/delete'],
    ];

    public function up(): void
    {
        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $ids = [];

        $accountsGroup = DB::table('menus')->where('route_path', '/journalentry')->value('parent_id');

        if ($accountsGroup !== null && ! DB::table('menus')->where('route_path', self::PAGE)->exists()) {
            $pageId = DB::table('menus')->insertGetId($this->row([
                'parent_id' => $accountsGroup, 'name' => 'Tax Exemptions', 'icon' => 'ShieldOff', 'route_name' => 'taxexemption', 'route_path' => self::PAGE,
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
