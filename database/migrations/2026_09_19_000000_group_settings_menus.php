<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Route path => sort order inside the Settings group, in display order.
     *
     * @var array<string, int>
     */
    private const GROUPED = [
        '/menu' => 1,
        '/currency' => 2,
        '/timezone' => 3,
        '/software/setting' => 4,
        '/country' => 5,
        '/state' => 6,
        '/city' => 7,
    ];

    /**
     * Route path => original root sort order, used to restore on rollback.
     *
     * @var array<string, int>
     */
    private const ORIGINAL_SORT = [
        '/menu' => 1,
        '/currency' => 1,
        '/timezone' => 1,
        '/software/setting' => 13,
        '/country' => 14,
        '/state' => 15,
        '/city' => 16,
    ];

    public function up(): void
    {
        $paths = array_keys(self::GROUPED);

        $parentId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Settings')
            ->where('type', 2)
            ->value('id');

        if ($parentId === null) {
            $movingIds = DB::table('menus')
                ->whereNull('parent_id')
                ->whereIn('route_path', $paths)
                ->pluck('id');

            $sortOrder = (int) DB::table('menus')
                ->whereNull('parent_id')
                ->whereNotIn('id', $movingIds)
                ->max('sort_order');

            // Group parents (User Management, Accounts...) have no route of their own. The live
            // column is nullable, but a schema built purely from migrations declares it NOT NULL.
            $routePathNullable = (bool) (collect(Schema::getColumns('menus'))
                ->firstWhere('name', 'route_path')['nullable'] ?? false);

            $parent = [
                'parent_id' => null,
                'name' => 'Settings',
                'icon' => 'Cog',
                'route_name' => '',
                'route_path' => $routePathNullable ? null : '',
                'menu_color' => '#199683',
                'sort_order' => $sortOrder + 1,
                'is_hidden' => 0,
                'is_active' => 1,
                'is_permission' => 1,
                'type' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('menus', 'is_admin')) {
                $parent['is_admin'] = 0;
            }

            $parentId = DB::table('menus')->insertGetId($parent);
        }

        foreach (self::GROUPED as $path => $order) {
            DB::table('menus')
                ->whereNull('parent_id')
                ->where('route_path', $path)
                ->update([
                    'parent_id' => $parentId,
                    'sort_order' => $order,
                    'updated_at' => now(),
                ]);
        }

        $this->flushSidebarCache();
    }

    public function down(): void
    {
        $parentId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Settings')
            ->where('type', 2)
            ->value('id');

        if ($parentId !== null) {
            foreach (self::ORIGINAL_SORT as $path => $order) {
                DB::table('menus')
                    ->where('parent_id', $parentId)
                    ->where('route_path', $path)
                    ->update([
                        'parent_id' => null,
                        'sort_order' => $order,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('menus')->where('id', $parentId)->delete();
        }

        $this->flushSidebarCache();
    }

    private function flushSidebarCache(): void
    {
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            Cache::forget("user_menu_permissions_tree:{$roleId}");
        }
    }
};
