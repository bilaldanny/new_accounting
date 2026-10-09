<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds three pages under the CRM group created by `2026_10_01_100100_add_crm_leads_menu.php`:
 * Opportunities (standard CRUD list), Pipeline Board (the Kanban view), and Pipeline Stages (the
 * configurable stage master data, following the Lead Sources pattern). Does nothing if the CRM group
 * is missing (same guard the other CRM menu migrations use).
 *
 * Permissions are not granted here: roles get the rows from the Role Permission screen.
 */
return new class extends Migration
{
    /**
     * @var list<array{path: string, name: string, icon: string, route: string, children: list<array{string,string,string}>}>
     */
    private const PAGES = [
        [
            'path' => '/opportunities',
            'name' => 'Opportunities',
            'icon' => 'Target',
            'route' => 'opportunities',
            'children' => [
                ['Add Opportunity', 'opportunities.add', '/opportunities/add'],
                ['Edit Opportunity', 'opportunities.edit', '/opportunities/:id/edit'],
                ['Delete Opportunity', 'opportunities.delete', '/opportunities/delete'],
                ['View Opportunity', 'opportunities.view', '/opportunities/:id/view'],
                ['Restore Opportunity', 'opportunities.restore', '/opportunities/restore'],
                ['Opportunity Export', 'opportunities.export', '/opportunities/export'],
            ],
        ],
        [
            'path' => '/pipeline',
            'name' => 'Pipeline Board',
            'icon' => 'Columns3',
            'route' => 'pipeline',
            'children' => [],
        ],
        [
            'path' => '/pipelinestages',
            'name' => 'Pipeline Stages',
            'icon' => 'ListOrdered',
            'route' => 'pipelinestages',
            'children' => [
                ['Add Pipeline Stage', 'pipelinestages.add', '/pipelinestages/add'],
                ['Edit Pipeline Stage', 'pipelinestages.edit', '/pipelinestages/:id/edit'],
                ['Delete Pipeline Stage', 'pipelinestages.delete', '/pipelinestages/delete'],
                ['View Pipeline Stage', 'pipelinestages.view', '/pipelinestages/:id/view'],
                ['Restore Pipeline Stage', 'pipelinestages.restore', '/pipelinestages/restore'],
                ['Pipeline Stage Export', 'pipelinestages.export', '/pipelinestages/export'],
            ],
        ],
    ];

    public function up(): void
    {
        $groupId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'CRM')
            ->where('type', 2)
            ->value('id');

        if ($groupId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        foreach (self::PAGES as $page) {
            if (DB::table('menus')->where('route_path', $page['path'])->exists()) {
                continue;
            }

            $pageId = DB::table('menus')->insertGetId($this->row([
                'parent_id' => $groupId,
                'name' => $page['name'],
                'icon' => $page['icon'],
                'route_name' => $page['route'],
                'route_path' => $page['path'],
                'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
                'is_hidden' => 0,
                'type' => 1,
            ], $hasAdminColumn));

            foreach ($page['children'] as $index => [$name, $routeName, $path]) {
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
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = collect();

        foreach (self::PAGES as $page) {
            $ids = $ids->merge(
                DB::table('menus')->where('route_path', $page['path'])->orWhere('route_path', 'like', $page['path'].'/%')->pluck('id')
            );
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
