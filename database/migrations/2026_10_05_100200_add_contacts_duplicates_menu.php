<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the "Duplicate Contacts" page under the Contacts group (id 53 in live data), with the hidden
 * permission row its controller checks for the merge action. Does nothing if the Contacts group is
 * missing (same anchor-guard convention as the other menu migrations).
 *
 * Permissions are not granted here: roles get the rows from the Role Permission screen.
 */
return new class extends Migration
{
    private const PATH = '/contacts/duplicates';

    public function up(): void
    {
        if (DB::table('menus')->where('route_path', self::PATH)->exists()) {
            return;
        }

        $groupId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Contacts')
            ->where('type', 2)
            ->value('id');

        if ($groupId === null) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');

        $pageId = DB::table('menus')->insertGetId($this->row([
            'parent_id' => $groupId,
            'name' => 'Duplicate Contacts',
            'icon' => 'fal fa-clone',
            'route_name' => 'contacts.duplicates',
            'route_path' => self::PATH,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
            'is_hidden' => 0,
            'type' => 1,
        ], $hasAdminColumn));

        DB::table('menus')->insert($this->row([
            'parent_id' => $pageId,
            'name' => 'Merge Contacts',
            'icon' => 'Grid',
            'route_name' => 'contacts.duplicates.merge',
            'route_path' => self::PATH.'/merge',
            'sort_order' => 1,
            'is_hidden' => 1,
            'type' => 1,
        ], $hasAdminColumn));

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('menus')->where('route_path', self::PATH)->orWhere('route_path', 'like', self::PATH.'/%')->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function row(array $row, bool $hasAdminColumn): array
    {
        $row += [
            'menu_color' => '#6a0dad',
            'is_active' => 1,
            'is_permission' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($hasAdminColumn) {
            $row['is_admin'] = 0;
        }

        return $row;
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
