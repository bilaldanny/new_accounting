<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PATH = '/customersubscriptioninvoices';

    public function up(): void
    {
        if (DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $groupId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Subscriptions')
            ->where('type', 2)
            ->value('id');

        if ($groupId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        $pageId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $groupId,
            'name' => 'Subscription Invoices',
            'icon' => 'Receipt',
            'route_name' => 'customersubscriptioninvoices',
            'route_path' => self::PATH,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
            'is_hidden' => 0,
            'type' => 1,
        ], $hasAdminColumn));

        $children = [
            ['Generate Customer Subscription Invoice', 'customersubscriptioninvoices.add', '/customersubscriptioninvoices/add'],
            ['Edit Customer Subscription Invoice', 'customersubscriptioninvoices.edit', '/customersubscriptioninvoices/:id/edit'],
        ];

        foreach ($children as $index => [$name, $routeName, $path]) {
            DB::table('menus')->insert($this->row([
                'parent_id' => $pageId,
                'name' => $name,
                'icon' => 'Grid',
                'route_name' => $routeName,
                'route_path' => $path,
                'sort_order' => $index + 1,
                'is_hidden' => 1,
                'type' => 1,
            ], $hasAdminColumn));
        }

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
