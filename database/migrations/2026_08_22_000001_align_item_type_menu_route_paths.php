<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Align item type permission paths with the web route prefix (`itemtype`).
     */
    public function up(): void
    {
        $paths = [
            '/item-type/export' => '/itemtype/export',
            '/item-type/import' => '/itemtype/import',
            '/item-type/trash' => '/itemtype/trash',
        ];

        foreach ($paths as $from => $to) {
            DB::table('menus')->where('route_path', $from)->update(['route_path' => $to]);
        }
    }

    public function down(): void
    {
        $paths = [
            '/itemtype/export' => '/item-type/export',
            '/itemtype/import' => '/item-type/import',
            '/itemtype/trash' => '/item-type/trash',
        ];

        foreach ($paths as $from => $to) {
            DB::table('menus')->where('route_path', $from)->update(['route_path' => $to]);
        }
    }
};
