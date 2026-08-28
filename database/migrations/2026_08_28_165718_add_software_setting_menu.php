<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route_path', '/software/setting')->exists()) {
            return;
        }

        $payload = [
            'parent_id' => null,
            'name' => 'Software Setting',
            'icon' => 'Cog',
            'route_name' => 'software.setting',
            'route_path' => '/software/setting',
            'menu_color' => '#199683',
            'sort_order' => ((int) DB::table('menus')->max('sort_order')) + 1,
            'is_hidden' => 0,
            'is_active' => 1,
            'is_permission' => 1,
            'type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('menus', 'is_admin')) {
            $payload['is_admin'] = 1;
        }

        DB::table('menus')->insert($payload);
    }

    public function down(): void
    {
        DB::table('menus')
            ->where('route_path', '/software/setting')
            ->delete();
    }
};
