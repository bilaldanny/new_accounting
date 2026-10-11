<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Portal Requests page (what customers and suppliers sent in through the portal), in the Contacts group next to
 * Customer, with its hidden permission row for approving and rejecting. Granted to the global companyadmin role; the
 * superadmin needs no row. Skipped when the Customer page is missing.
 */
return new class extends Migration
{
    private const PAGE = '/portalrequests';

    private const APPROVE = '/portalrequests/approve';

    public function up(): void
    {
        $parentId = DB::table('menus')->where('route_path', '/customer')->value('parent_id');

        if ($parentId === null || DB::table('menus')->where('route_path', self::PAGE)->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        $pageId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $parentId, 'name' => 'Portal Requests', 'icon' => 'Inbox', 'route_name' => 'portalrequests',
            'route_path' => self::PAGE, 'sort_order' => (int) DB::table('menus')->where('parent_id', $parentId)->max('sort_order') + 1,
            'is_hidden' => 0,
        ], $hasAdminColumn));

        $approveId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $pageId, 'name' => 'Approve Portal Request', 'icon' => 'Grid', 'route_name' => 'portalrequests.approve',
            'route_path' => self::APPROVE, 'sort_order' => 1, 'is_hidden' => 1,
        ], $hasAdminColumn));

        $roleId = DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->whereNull('deleted_at')->orderBy('id')->value('id');

        if ($roleId !== null) {
            DB::table('permissions')->insert(array_map(fn (int $menuId): array => [
                'company_id' => null, 'branch_id' => null, 'department_id' => null,
                'role_id' => $roleId, 'menu_id' => $menuId, 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], [$pageId, $approveId]));
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->whereIn('route_path', [self::PAGE, self::APPROVE])->pluck('id');

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
