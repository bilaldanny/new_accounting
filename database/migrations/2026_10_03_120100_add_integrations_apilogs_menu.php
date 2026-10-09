<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PATH = '/apilogs';

    public function up(): void
    {
        if (DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $groupId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Integrations')
            ->where('type', 2)
            ->value('id');

        if ($groupId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        DB::table('menus')->insertGetId($this->row([
            'parent_id' => $groupId,
            'name' => 'API Logs',
            'icon' => 'ScrollText',
            'route_name' => 'apilogs',
            'route_path' => self::PATH,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
            'is_hidden' => 0,
            'type' => 1,
        ], $hasAdminColumn));

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')
            ->where('route_path', self::PATH)
            ->orWhere('route_path', 'like', self::PATH.'/%')
            ->pluck('id');

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
        $row += [
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
