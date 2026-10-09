<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the CRM group (a sidebar dropdown with no route of its own, following the same pattern as
 * the Reports group) and its first page, Leads, with the hidden permission rows its controller and
 * pages check: add, edit, delete, view, restore (from the trash page) and export. Further CRM & Sales
 * Pipeline pages (Opportunities, Pipeline, Activities, CRM Analytics) go under the same group.
 *
 * Permissions are not granted here: roles get the rows from the Role Permission screen.
 */
return new class extends Migration
{
    private const PATH = '/leads';

    public function up(): void
    {
        if (DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        $groupId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'CRM')
            ->where('type', 2)
            ->value('id');

        if ($groupId === null) {
            // Group parents have no route of their own. The live column is nullable, but a schema
            // built purely from migrations declares it NOT NULL.
            $routePathNullable = (bool) (collect(Schema::getColumns('menus'))
                ->firstWhere('name', 'route_path')['nullable'] ?? false);

            $groupId = DB::table('menus')->insertGetId($this->row([
                'parent_id' => null,
                'name' => 'CRM',
                'icon' => 'Handshake',
                'route_name' => '',
                'route_path' => $routePathNullable ? null : '',
                'sort_order' => (int) DB::table('menus')->whereNull('parent_id')->max('sort_order') + 1,
                'is_hidden' => 0,
                'type' => 2,
            ], $hasAdminColumn));
        }

        $pageId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $groupId,
            'name' => 'Leads',
            'icon' => 'UserPlus',
            'route_name' => 'leads',
            'route_path' => self::PATH,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
            'is_hidden' => 0,
            'type' => 1,
        ], $hasAdminColumn));

        $children = [
            ['Add Lead', 'leads.add', '/leads/add'],
            ['Edit Lead', 'leads.edit', '/leads/:id/edit'],
            ['Delete Lead', 'leads.delete', '/leads/delete'],
            ['View Lead', 'leads.view', '/leads/:id/view'],
            ['Restore Lead', 'leads.restore', '/leads/restore'],
            ['Lead Export', 'leads.export', '/leads/export'],
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
        // Exact page path OR its children (/leads/add, /leads/:id/edit, ...) — NOT a bare "LIKE
        // /leads%", which would also swallow the unrelated /leadsources% page.
        $ids = DB::table('menus')
            ->where('route_path', self::PATH)
            ->orWhere('route_path', 'like', self::PATH.'/%')
            ->pluck('id');

        $groupId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'CRM')
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
