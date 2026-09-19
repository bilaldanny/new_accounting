<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route_path', '/consumer')->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $sortOrder = (int) DB::table('menus')->max('sort_order');

        $parent = [
            'parent_id' => null,
            'name' => 'Consumer',
            'icon' => 'UserCircle',
            'route_name' => 'consumer',
            'route_path' => '/consumer',
            'menu_color' => '#199683',
            'sort_order' => $sortOrder + 1,
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

        $children = [
            ['name' => 'Add Consumer', 'route_name' => 'consumer.add', 'route_path' => '/consumer/add'],
            ['name' => 'Edit Consumer', 'route_name' => 'consumer.edit', 'route_path' => '/consumer/:id/edit'],
            ['name' => 'Delete Consumer', 'route_name' => 'consumer.delete', 'route_path' => '/consumer/delete'],
        ];

        foreach ($children as $index => $child) {
            $payload = [
                'parent_id' => $parentId,
                'name' => $child['name'],
                'icon' => 'Grid',
                'route_name' => $child['route_name'],
                'route_path' => $child['route_path'],
                'menu_color' => '#199683',
                'sort_order' => $index + 1,
                'is_hidden' => 1,
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

    public function down(): void
    {
        DB::table('menus')
            ->whereIn('route_path', ['/consumer', '/consumer/add', '/consumer/:id/edit', '/consumer/delete'])
            ->delete();
    }
};
