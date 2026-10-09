<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the Integrations group and its first page, Webhooks. API Logs goes under the same group. API
 * Keys stays where it already is (Settings), reused as-is — not moved here.
 */
return new class extends Migration
{
    private const PATH = '/webhooks';

    public function up(): void
    {
        if (DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        $groupId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Integrations')
            ->where('type', 2)
            ->value('id');

        if ($groupId === null) {
            $routePathNullable = (bool) (collect(Schema::getColumns('menus'))
                ->firstWhere('name', 'route_path')['nullable'] ?? false);

            $groupId = DB::table('menus')->insertGetId($this->row([
                'parent_id' => null,
                'name' => 'Integrations',
                'icon' => 'Plug',
                'route_name' => '',
                'route_path' => $routePathNullable ? null : '',
                'sort_order' => (int) DB::table('menus')->whereNull('parent_id')->max('sort_order') + 1,
                'is_hidden' => 0,
                'type' => 2,
            ], $hasAdminColumn));
        }

        $pageId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $groupId,
            'name' => 'Webhooks',
            'icon' => 'Webhook',
            'route_name' => 'webhooks',
            'route_path' => self::PATH,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
            'is_hidden' => 0,
            'type' => 1,
        ], $hasAdminColumn));

        $children = [
            ['Add Webhook', 'webhooks.add', '/webhooks/add'],
            ['Edit Webhook', 'webhooks.edit', '/webhooks/:id/edit'],
            ['Delete Webhook', 'webhooks.delete', '/webhooks/delete'],
            ['Restore Webhook', 'webhooks.restore', '/webhooks/restore'],
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

        $groupId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Integrations')
            ->where('type', 2)
            ->value('id');

        if ($groupId !== null && DB::table('menus')->where('parent_id', $groupId)->whereNotIn('id', $ids)->doesntExist()) {
            $ids = $ids->push($groupId);
        }

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
