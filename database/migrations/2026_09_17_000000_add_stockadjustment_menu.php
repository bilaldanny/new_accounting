<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route_path', '/stockadjustment')->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $sortOrder = (int) DB::table('menus')->max('sort_order');

        $parent = [
            'parent_id' => null,
            'name' => 'Stock Adjustment',
            'icon' => 'SlidersHorizontal',
            'route_name' => 'stockadjustment',
            'route_path' => '/stockadjustment',
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
            ['name' => 'Add Stock Adjustment', 'route_name' => 'stockadjustment.add', 'route_path' => '/stockadjustment/add'],
            ['name' => 'Edit Stock Adjustment', 'route_name' => 'stockadjustment.edit', 'route_path' => '/stockadjustment/:id/edit'],
            ['name' => 'Delete Stock Adjustment', 'route_name' => 'stockadjustment.delete', 'route_path' => '/stockadjustment/delete'],
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
            ->whereIn('route_path', ['/stockadjustment', '/stockadjustment/add', '/stockadjustment/:id/edit', '/stockadjustment/delete'])
            ->delete();
    }
};
