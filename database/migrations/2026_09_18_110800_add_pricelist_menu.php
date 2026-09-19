<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route_path', '/pricelist')->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $productsParentId = DB::table('menus')->where('route_path', '/product')->value('parent_id');
        $sortOrder = (int) DB::table('menus')->where('parent_id', $productsParentId)->max('sort_order');

        $parent = [
            'parent_id' => $productsParentId,
            'name' => 'Price List',
            'icon' => 'PieChart',
            'route_name' => 'pricelist',
            'route_path' => '/pricelist',
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
            ['name' => 'Add Price List', 'route_name' => 'pricelist.add', 'route_path' => '/pricelist/add'],
            ['name' => 'Edit Price List', 'route_name' => 'pricelist.edit', 'route_path' => '/pricelist/:id/edit'],
            ['name' => 'Delete Price List', 'route_name' => 'pricelist.delete', 'route_path' => '/pricelist/delete'],
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
            ->whereIn('route_path', ['/pricelist', '/pricelist/add', '/pricelist/:id/edit', '/pricelist/delete'])
            ->delete();
    }
};
