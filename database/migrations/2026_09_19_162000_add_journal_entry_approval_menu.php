<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds "Journal Entry Approval" next to Purchase Approval and Sell Approval in the Approval group.
 *
 * The approve, reject and print permission rows (`/journalentry/:id/approve|reject|print`) already
 * exist as hidden rows; this adds the visible list page they act on. Permissions are not granted
 * here: roles get the row from the Role Permission screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route_path', '/journalentry/approval')->exists()) {
            return;
        }

        $approvalGroupId = DB::table('menus')->where('route_path', '/purchase/approval')->value('parent_id')
            ?? DB::table('menus')->where('route_path', '/sell/approval')->value('parent_id');

        if ($approvalGroupId === null) {
            return;
        }

        $row = [
            'parent_id' => $approvalGroupId,
            'name' => 'Journal Entry Approval',
            'icon' => 'bx bx-buildings',
            'route_name' => 'journalentryapprovals',
            'route_path' => '/journalentry/approval',
            'menu_color' => '#6a0dad',
            'sort_order' => (int) DB::table('menus')->where('parent_id', $approvalGroupId)->max('sort_order') + 1,
            'is_hidden' => 0,
            'is_active' => 1,
            'is_permission' => 0,
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
        $ids = DB::table('menus')->where('route_path', '/journalentry/approval')->pluck('id');

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
