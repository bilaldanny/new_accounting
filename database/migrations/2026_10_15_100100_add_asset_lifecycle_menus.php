<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the hidden permission rows for asking for a revaluation, an impairment or a disposal under the Fixed Assets
 * page, and the Fixed Asset Approval page under the existing "Approval" group (with a hidden `/assetapproval/reject`
 * row, the same convention as Credit Limit Approval). The page's own path is the approve permission. Each part is
 * skipped when its anchor (the `/fixedasset` row, the Approval group) is missing. All of it is granted to the global
 * companyadmin role; other roles come from the Role Permission screen (approving needs `/assetapproval`, rejecting
 * `/assetapproval/reject`).
 */
return new class extends Migration
{
    private const HIDDEN = [
        ['Revalue Fixed Asset', 'fixedasset.revalue', '/fixedasset/revalue'],
        ['Impair Fixed Asset', 'fixedasset.impair', '/fixedasset/impair'],
        ['Dispose Fixed Asset', 'fixedasset.dispose', '/fixedasset/dispose'],
    ];

    private const APPROVAL = '/assetapproval';

    private const REJECT = '/assetapproval/reject';

    public function up(): void
    {
        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $ids = [];

        $assetPage = DB::table('menus')->where('route_path', '/fixedasset')->value('id');

        if ($assetPage !== null) {
            foreach (self::HIDDEN as [$name, $routeName, $path]) {
                if (! DB::table('menus')->where('route_path', $path)->exists()) {
                    $ids[] = DB::table('menus')->insertGetId($this->row([
                        'parent_id' => $assetPage, 'name' => $name, 'icon' => 'Grid', 'route_name' => $routeName, 'route_path' => $path,
                        'sort_order' => (int) DB::table('menus')->where('parent_id', $assetPage)->max('sort_order') + 1, 'is_hidden' => 1,
                    ], $hasAdminColumn));
                }
            }
        }

        $approvalGroup = DB::table('menus')->whereNull('parent_id')->where('name', 'Approval')->where('type', 2)->value('id');

        if ($approvalGroup !== null && ! DB::table('menus')->where('route_path', self::APPROVAL)->exists()) {
            $pageId = DB::table('menus')->insertGetId($this->row([
                'parent_id' => $approvalGroup, 'name' => 'Fixed Asset Approval', 'icon' => 'Building', 'route_name' => 'assetapprovals', 'route_path' => self::APPROVAL,
                'sort_order' => (int) DB::table('menus')->where('parent_id', $approvalGroup)->max('sort_order') + 1, 'is_hidden' => 0,
            ], $hasAdminColumn));

            $ids[] = $pageId;
            $ids[] = DB::table('menus')->insertGetId($this->row([
                'parent_id' => $pageId, 'name' => 'Reject Fixed Asset Request', 'icon' => 'Grid', 'route_name' => 'assetapprovals.reject', 'route_path' => self::REJECT,
                'sort_order' => 1, 'is_hidden' => 1,
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
        $paths = array_merge(array_column(self::HIDDEN, 2), [self::APPROVAL, self::REJECT]);
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
