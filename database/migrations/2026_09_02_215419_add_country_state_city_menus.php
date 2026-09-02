<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return list<array{name: string, route_name: string, route_path: string, icon: string}>
     */
    protected function resources(): array
    {
        return [
            [
                'name' => 'Country',
                'route_name' => 'country',
                'route_path' => '/country',
                'icon' => 'Building',
            ],
            [
                'name' => 'State',
                'route_name' => 'state',
                'route_path' => '/state',
                'icon' => 'Buildings',
            ],
            [
                'name' => 'City',
                'route_name' => 'city',
                'route_path' => '/city',
                'icon' => 'Home',
            ],
        ];
    }

    /**
     * @return list<array{name: string, suffix: string, hidden: bool}>
     */
    protected function permissionChildren(): array
    {
        return [
            ['name' => 'Add', 'suffix' => '/add', 'hidden' => true],
            ['name' => 'Edit', 'suffix' => '/:id/edit', 'hidden' => true],
            ['name' => 'Delete', 'suffix' => '/delete', 'hidden' => true],
            ['name' => 'Trash', 'suffix' => '/trash', 'hidden' => true],
            ['name' => 'Restore', 'suffix' => '/restore', 'hidden' => true],
        ];
    }

    public function up(): void
    {
        $sortOrder = (int) DB::table('menus')->max('sort_order');
        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        foreach ($this->resources() as $resource) {
            if (DB::table('menus')->where('route_path', $resource['route_path'])->exists()) {
                continue;
            }

            $sortOrder++;

            $parent = [
                'parent_id' => null,
                'name' => $resource['name'],
                'icon' => $resource['icon'],
                'route_name' => $resource['route_name'],
                'route_path' => $resource['route_path'],
                'menu_color' => '#199683',
                'sort_order' => $sortOrder,
                'is_hidden' => 0,
                'is_active' => 1,
                'is_permission' => 1,
                'type' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($hasAdminColumn) {
                $parent['is_admin'] = 0;
            }

            $parentId = DB::table('menus')->insertGetId($parent);

            foreach ($this->permissionChildren() as $index => $child) {
                $childPath = $resource['route_path'].$child['suffix'];

                if (DB::table('menus')->where('route_path', $childPath)->exists()) {
                    continue;
                }

                $payload = [
                    'parent_id' => $parentId,
                    'name' => $child['name'],
                    'icon' => 'Grid',
                    'route_name' => $resource['route_name'].'.'.strtolower($child['name']),
                    'route_path' => $childPath,
                    'menu_color' => '#199683',
                    'sort_order' => $index + 1,
                    'is_hidden' => $child['hidden'] ? 1 : 0,
                    'is_active' => 1,
                    'is_permission' => 1,
                    'type' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($hasAdminColumn) {
                    $payload['is_admin'] = 0;
                }

                DB::table('menus')->insert($payload);
            }
        }
    }

    public function down(): void
    {
        $paths = [];

        foreach ($this->resources() as $resource) {
            $paths[] = $resource['route_path'];

            foreach ($this->permissionChildren() as $child) {
                $paths[] = $resource['route_path'].$child['suffix'];
            }
        }

        DB::table('menus')->whereIn('route_path', $paths)->delete();
    }
};
