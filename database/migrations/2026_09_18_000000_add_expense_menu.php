<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route_path', '/expense')->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $accountsParentId = DB::table('menus')->where('route_path', '/acpayment')->value('parent_id');
        $sortOrder = (int) DB::table('menus')->where('parent_id', $accountsParentId)->max('sort_order');

        $parent = [
            'parent_id' => $accountsParentId,
            'name' => 'Expense',
            'icon' => 'Receipt',
            'route_name' => '',
            'route_path' => '/expense',
            'menu_color' => '#199683',
            'sort_order' => $sortOrder + 1,
            'is_hidden' => 0,
            'is_active' => 1,
            'is_permission' => 0,
            'type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($hasAdminColumn) {
            $parent['is_admin'] = 0;
        }

        $parentId = DB::table('menus')->insertGetId($parent);

        $children = [
            ['name' => 'Add Expense', 'route_name' => '', 'route_path' => '/expense/add'],
            ['name' => 'Edit Expense', 'route_name' => 'editexpense', 'route_path' => '/expense/:id/edit'],
            ['name' => 'View Expense', 'route_name' => 'viewexpense', 'route_path' => '/expense/:id/view'],
            ['name' => 'Delete Expense', 'route_name' => 'expenses.destroy', 'route_path' => '/expense/delete'],
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
                'is_permission' => 0,
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
            ->whereIn('route_path', ['/expense', '/expense/add', '/expense/:id/edit', '/expense/:id/view', '/expense/delete'])
            ->delete();
    }
};
