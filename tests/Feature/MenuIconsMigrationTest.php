<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('menu icons migration stores boxicons vue component names', function () {
    if (! Schema::hasTable('menus')) {
        $this->markTestSkipped('menus table is not available.');
    }

    expect(DB::table('menus')->where('id', 2)->value('icon'))->toBe('Menu');
    expect(DB::table('menus')->where('id', 17)->value('icon'))->toBe('Building');
    expect(DB::table('menus')->where('id', 26)->value('icon'))->toBe('UserCircle');
    expect(DB::table('menus')->where('id', 48)->value('icon'))->toBe('Receipt');
    expect(DB::table('menus')->where('id', 36)->value('icon'))->toBe('User');
});

test('update menu icons migration converts a legacy icon value', function () {
    if (! Schema::hasTable('menus')) {
        $this->markTestSkipped('menus table is not available.');
    }

    $originalIcon = DB::table('menus')->where('id', 2)->value('icon');

    DB::table('menus')->where('id', 2)->update(['icon' => 'fal fa-bars']);

    $migration = require database_path('migrations/2026_08_06_181354_update_menu_icons_to_boxicons.php');
    $migration->up();

    expect(DB::table('menus')->where('id', 2)->value('icon'))->toBe('Menu');

    DB::table('menus')->where('id', 2)->update(['icon' => $originalIcon]);
});
