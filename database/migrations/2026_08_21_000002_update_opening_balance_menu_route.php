<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('menus')
            ->where('route_path', '/openingbalance')
            ->orWhere('route_name', 'openingbalance')
            ->update([
                'route_name' => 'opening-balance',
                'route_path' => '/opening-balance',
                'is_hidden' => 0,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('menus')
            ->where('route_name', 'opening-balance')
            ->update([
                'route_name' => 'openingbalance',
                'route_path' => '/openingbalance',
                'updated_at' => now(),
            ]);
    }
};
