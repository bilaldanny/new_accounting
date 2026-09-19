<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route_path', '/printlabel')->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $productsParentId = DB::table('menus')->where('route_path', '/product')->value('parent_id');
        $sortOrder = (int) DB::table('menus')->where('parent_id', $productsParentId)->max('sort_order');

        $parent = [
            'parent_id' => $productsParentId,
            'name' => 'Print Label',
            'icon' => 'Tag',
            'route_name' => 'printlabel',
            'route_path' => '/printlabel',
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

        DB::table('menus')->insertGetId($parent);
    }

    public function down(): void
    {
        DB::table('menus')
            ->where('route_path', '/printlabel')
            ->delete();
    }
};
