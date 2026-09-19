<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route_path', '/bankissuer')->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $contactsParentId = DB::table('menus')->where('route_path', '/bank')->value('parent_id');
        $sortOrder = (int) DB::table('menus')->where('parent_id', $contactsParentId)->max('sort_order');

        $parent = [
            'parent_id' => $contactsParentId,
            'name' => 'Bank Issuer',
            'icon' => 'Bank',
            'route_name' => 'bankissuer',
            'route_path' => '/bankissuer',
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
            ['name' => 'Add Bank Issuer', 'route_name' => 'bankissuer.add', 'route_path' => '/bankissuer/add'],
            ['name' => 'Edit Bank Issuer', 'route_name' => 'bankissuer.edit', 'route_path' => '/bankissuer/:id/edit'],
            ['name' => 'Delete Bank Issuer', 'route_name' => 'bankissuer.delete', 'route_path' => '/bankissuer/delete'],
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
            ->whereIn('route_path', ['/bankissuer', '/bankissuer/add', '/bankissuer/:id/edit', '/bankissuer/delete'])
            ->delete();
    }
};
