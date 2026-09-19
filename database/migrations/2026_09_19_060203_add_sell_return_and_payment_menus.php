<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sell Return, Sell Payment and Purchase Payment already have working routes, controllers and pages
 * but no sidebar rows. Each row is attached under the same group as its anchor menu (`/sell` or
 * `/purchase`). The hidden children are the permission keys the controllers check.
 */
return new class extends Migration
{
    /**
     * @return list<array{anchor: string, name: string, route_name: string, route_path: string, children: list<array{name: string, route_name: string, route_path: string}>}>
     */
    private function menus(): array
    {
        return [
            [
                'anchor' => '/sell',
                'name' => 'Sell Return',
                'route_name' => 'sellreturn',
                'route_path' => '/sell/return',
                'children' => [
                    ['name' => 'Add Sell Return', 'route_name' => 'addsellreturn', 'route_path' => '/sell/return/add'],
                    ['name' => 'Edit Sell Return', 'route_name' => 'editsellreturn', 'route_path' => '/sell/return/:id/edit'],
                    ['name' => 'Delete Sell Return', 'route_name' => 'sellreturns.destroy', 'route_path' => '/sell/return/delete'],
                    ['name' => 'Restore Sell Return', 'route_name' => 'sellreturns.restore', 'route_path' => '/sell/return/restore'],
                    ['name' => 'View Sell Return', 'route_name' => 'viewsellreturn', 'route_path' => '/sell/return/:id/view'],
                ],
            ],
            [
                'anchor' => '/sell',
                'name' => 'Sell Payment',
                'route_name' => 'sellpayment',
                'route_path' => '/sell/payment',
                'children' => [
                    ['name' => 'Add Sell Payment', 'route_name' => 'addsellpayment', 'route_path' => '/sell/payment/add'],
                    ['name' => 'Edit Sell Payment', 'route_name' => 'editsellpayment', 'route_path' => '/sell/payment/:id/edit'],
                    ['name' => 'Delete Sell Payment', 'route_name' => 'sellpayments.destroy', 'route_path' => '/sell/payment/delete'],
                ],
            ],
            [
                'anchor' => '/purchase',
                'name' => 'Purchase Payment',
                'route_name' => 'purchasepayment',
                'route_path' => '/purchase/payment',
                'children' => [
                    ['name' => 'Add Purchase Payment', 'route_name' => 'addpurchasepayment', 'route_path' => '/purchase/payment/add'],
                    ['name' => 'Edit Purchase Payment', 'route_name' => 'editpurchasepayment', 'route_path' => '/purchase/payment/:id/edit'],
                    ['name' => 'Delete Purchase Payment', 'route_name' => 'purchasepayments.destroy', 'route_path' => '/purchase/payment/delete'],
                ],
            ],
        ];
    }

    public function up(): void
    {
        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        foreach ($this->menus() as $menu) {
            if (DB::table('menus')->where('route_path', $menu['route_path'])->exists()) {
                continue;
            }

            $parentId = DB::table('menus')->where('route_path', $menu['anchor'])->value('parent_id');

            if ($parentId === null) {
                continue;
            }

            $parent = [
                'parent_id' => $parentId,
                'name' => $menu['name'],
                'icon' => 'bx bx-buildings',
                'route_name' => $menu['route_name'],
                'route_path' => $menu['route_path'],
                'menu_color' => '#6a0dad',
                'sort_order' => (int) DB::table('menus')->where('parent_id', $parentId)->max('sort_order') + 1,
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

            $menuId = DB::table('menus')->insertGetId($parent);

            foreach ($menu['children'] as $index => $child) {
                $payload = [
                    'parent_id' => $menuId,
                    'name' => $child['name'],
                    'icon' => '',
                    'route_name' => $child['route_name'],
                    'route_path' => $child['route_path'],
                    'menu_color' => '#6a0dad',
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

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $paths = [];

        foreach ($this->menus() as $menu) {
            $paths[] = $menu['route_path'];

            foreach ($menu['children'] as $child) {
                $paths[] = $child['route_path'];
            }
        }

        $ids = DB::table('menus')->whereIn('route_path', $paths)->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
    }

    private function flushMenuCaches(): void
    {
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            Cache::forget("user_menu_permissions_tree:{$roleId}");
            Cache::forget("user_permission_paths:{$roleId}");
        }
    }
};
