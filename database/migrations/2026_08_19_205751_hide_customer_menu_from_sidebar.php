<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('menus')
            ->where('route_name', 'customer')
            ->update([
                'is_hidden' => 0,
                'sort_order' => 2,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('menus')
            ->where('route_name', 'customer')
            ->update([
                'is_hidden' => 1,
                'updated_at' => now(),
            ]);
    }
};
