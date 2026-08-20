<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('menus')
            ->where('route_name', 'chartofaccount')
            ->update([
                'route_name' => 'chart-of-account',
                'route_path' => '/chart-of-account',
                'is_hidden' => 0,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('menus')
            ->where('route_name', 'chart-of-account')
            ->update([
                'route_name' => 'chartofaccount',
                'updated_at' => now(),
            ]);
    }
};
