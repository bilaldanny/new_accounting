<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Orphaned Refunds page of Gift Card: refunds owed to customers for gift card payments whose card was
 * deleted for good (see App\Services\SaleGiftCards). It is one hidden permission row under the Gift Card page
 * (`/giftcard`), reached from a button on the Gift Card list the way Loyalty reaches its settings. The row is
 * the page's permission; settling a row still needs `/giftcard/topup`.
 *
 * The Gift Card page comes from 2026_09_21_210100_add_giftcard_menu, so this does nothing without it.
 * Permissions are not granted here (see the companyadmin grant migration that follows).
 */
return new class extends Migration
{
    private const PATH = '/giftcard/orphan-refunds';

    public function up(): void
    {
        if (DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $pageId = DB::table('menus')->where('route_path', '/giftcard')->value('id');

        if ($pageId === null) {
            return;
        }

        $row = [
            'parent_id' => $pageId,
            'name' => 'Gift Card Orphaned Refunds',
            'icon' => 'Grid',
            'route_name' => 'giftcard.orphan-refunds',
            'route_path' => self::PATH,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $pageId)->max('sort_order') + 1,
            'is_hidden' => 1,
            'menu_color' => '#199683',
            'is_active' => 1,
            'is_permission' => 1,
            'type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('menus', 'is_admin')) {
            $row['is_admin'] = 0;
        }

        DB::table('menus')->insert($row);

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->where('route_path', self::PATH)->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
    }

    private function flushMenuCaches(): void
    {
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            Cache::forget("user_menu_permissions_tree:{$roleId}");
            Cache::forget("user_permission_paths:{$roleId}");
            Cache::forget("user_menu_permissions:{$roleId}");
        }
    }
};
