<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('menu icons migration stores boxicons vue component names', function () {
    if (! Schema::hasTable('menus')) {
        $this->markTestSkipped('menus table is not available.');
    }

    // The icons are keyed by live menu id, which a schema built from migrations does not have, so
    // put the legacy values on those ids first and let the migration convert them.
    $legacyIcons = [2 => 'fal fa-bars', 17 => 'fal fa-building', 26 => 'fal fa-user', 48 => 'fal fa-file-invoice', 36 => 'bx bx-user'];

    foreach ($legacyIcons as $id => $legacyIcon) {
        if (DB::table('menus')->where('id', $id)->exists()) {
            DB::table('menus')->where('id', $id)->update(['icon' => $legacyIcon]);

            continue;
        }

        DB::table('menus')->insert([
            'id' => $id, 'parent_id' => null, 'name' => "Legacy {$id}", 'icon' => $legacyIcon, 'route_name' => '', 'route_path' => '',
            'menu_color' => '#000000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_permission' => 0, 'type' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    (require database_path('migrations/2026_08_06_181354_update_menu_icons_to_boxicons.php'))->up();

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
