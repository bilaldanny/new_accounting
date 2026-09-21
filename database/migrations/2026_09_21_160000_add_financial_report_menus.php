<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The financial statements under the Reports group: profit and loss and the balance sheet. Each page also
 * gets the hidden `/report/<name>/export` row that the table's Export buttons check.
 *
 * The Reports group is created by add_reports_menu_with_party_ledger, and a fresh schema has it too;
 * without it this does nothing. Permissions are not granted here: roles get the rows from the Role
 * Permission screen.
 */
return new class extends Migration
{
    /**
     * Page path => menu name.
     *
     * @var array<string, string>
     */
    private const REPORTS = [
        '/report/profit-loss' => 'Profit & Loss',
        '/report/balance-sheet' => 'Balance Sheet',
    ];

    public function up(): void
    {
        $groupId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Reports')
            ->where('type', 2)
            ->value('id');

        if ($groupId === null) {
            return;
        }

        foreach (self::REPORTS as $path => $name) {
            $pageId = DB::table('menus')->where('route_path', $path)->value('id');

            if ($pageId === null) {
                $pageId = $this->insert([
                    'parent_id' => $groupId,
                    'name' => $name,
                    'icon' => 'Receipt',
                    'route_name' => 'report.'.substr($path, strlen('/report/')),
                    'route_path' => $path,
                    'sort_order' => (int) DB::table('menus')->where('parent_id', $groupId)->max('sort_order') + 1,
                    'is_hidden' => 0,
                ]);
            }

            if (! DB::table('menus')->where('route_path', $path.'/export')->exists()) {
                $this->insert([
                    'parent_id' => $pageId,
                    'name' => $name.' Export',
                    'icon' => '',
                    'route_name' => 'report.'.substr($path, strlen('/report/')).'.export',
                    'route_path' => $path.'/export',
                    'sort_order' => 1,
                    'is_hidden' => 1,
                ]);
            }
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $paths = [];

        foreach (array_keys(self::REPORTS) as $path) {
            array_push($paths, $path, $path.'/export');
        }

        $ids = DB::table('menus')->whereIn('route_path', $paths)->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insert(array $row): int
    {
        $row += [
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

        return (int) DB::table('menus')->insertGetId($row);
    }

    private function flushMenuCaches(): void
    {
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            Cache::forget("user_menu_permissions_tree:{$roleId}");
            Cache::forget("user_permission_paths:{$roleId}");
        }
    }
};
