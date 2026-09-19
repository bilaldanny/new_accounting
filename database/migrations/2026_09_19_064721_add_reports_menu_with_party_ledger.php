<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the Reports group (a sidebar dropdown with no route of its own) and its first report,
 * the standalone Customer / Supplier Ledger. Further reports go under the same group.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        $groupId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Reports')
            ->where('type', 2)
            ->value('id');

        if ($groupId === null) {
            // Group parents have no route of their own. The live column is nullable, but a schema
            // built purely from migrations declares it NOT NULL.
            $routePathNullable = (bool) (collect(Schema::getColumns('menus'))
                ->firstWhere('name', 'route_path')['nullable'] ?? false);

            $group = [
                'parent_id' => null,
                'name' => 'Reports',
                'icon' => 'PieChart',
                'route_name' => '',
                'route_path' => $routePathNullable ? null : '',
                'menu_color' => '#199683',
                'sort_order' => (int) DB::table('menus')->whereNull('parent_id')->max('sort_order') + 1,
                'is_hidden' => 0,
                'is_active' => 1,
                'is_permission' => 1,
                'type' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($hasAdminColumn) {
                $group['is_admin'] = 0;
            }

            $groupId = DB::table('menus')->insertGetId($group);
        }

        if (! DB::table('menus')->where('route_path', '/report/ledger')->exists()) {
            $ledger = [
                'parent_id' => $groupId,
                'name' => 'Customer / Supplier Ledger',
                'icon' => 'Receipt',
                'route_name' => 'report.ledger',
                'route_path' => '/report/ledger',
                'menu_color' => '#199683',
                'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
                'is_hidden' => 0,
                'is_active' => 1,
                'is_permission' => 1,
                'type' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($hasAdminColumn) {
                $ledger['is_admin'] = 0;
            }

            DB::table('menus')->insert($ledger);
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->where('route_path', '/report/ledger')->pluck('id');

        $groupId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Reports')
            ->where('type', 2)
            ->value('id');

        if ($groupId !== null && DB::table('menus')->where('parent_id', $groupId)->whereNotIn('id', $ids)->doesntExist()) {
            $ids = $ids->push($groupId);
        }

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
    }

    private function flushMenuCaches(): void
    {
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            Cache::forget("user_menu_permissions_tree:{$roleId}");
            Cache::forget("user_permission_paths:{$roleId}");
        }
    }
};
