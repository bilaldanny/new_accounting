<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu rows for the new Credit/Debit Note voucher family: a visible `/creditdebitnote` list row in
 * the Accounts group (next to Journal Entry, Payment, Expense, Deposit, Fund Transfer), its hidden
 * add/edit/view/delete rows, its hidden approve/reject rows (the keys VoucherApprovalController
 * checks), and a visible `/creditdebitnote/approval` list row in the Approval group, the sibling of
 * Purchase/Sell/Journal Entry/Payment/Expense/Deposit/Fund Transfer Approval.
 *
 * Permissions are not granted here: roles get the rows from the Role Permission screen (except
 * companyadmin, granted by 2026_09_27_120000_grant_companyadmin_credit_debit_note_menu).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route_path', '/creditdebitnote')->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $accountsParentId = DB::table('menus')->where('route_path', '/acpayment')->value('parent_id');
        $sortOrder = (int) DB::table('menus')->where('parent_id', $accountsParentId)->max('sort_order');

        $parent = [
            'parent_id' => $accountsParentId,
            'name' => 'Credit/Debit Note',
            'icon' => 'Bank',
            'route_name' => '',
            'route_path' => '/creditdebitnote',
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
            ['name' => 'Add Credit/Debit Note', 'route_name' => '', 'route_path' => '/creditdebitnote/add'],
            ['name' => 'Edit Credit/Debit Note', 'route_name' => 'editcreditdebitnote', 'route_path' => '/creditdebitnote/:id/edit'],
            ['name' => 'View Credit/Debit Note', 'route_name' => 'viewcreditdebitnote', 'route_path' => '/creditdebitnote/:id/view'],
            ['name' => 'Delete Credit/Debit Note', 'route_name' => 'creditdebitnotes.destroy', 'route_path' => '/creditdebitnote/delete'],
            ['name' => 'Credit/Debit Note Approve', 'route_name' => 'creditdebitnoteapproval', 'route_path' => '/creditdebitnote/:id/approve'],
            ['name' => 'Credit/Debit Note Reject', 'route_name' => 'creditdebitnotereject', 'route_path' => '/creditdebitnote/:id/reject'],
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

        $approvalGroupId = DB::table('menus')->where('route_path', '/purchase/approval')->value('parent_id')
            ?? DB::table('menus')->where('route_path', '/sell/approval')->value('parent_id');

        if ($approvalGroupId !== null && ! DB::table('menus')->where('route_path', '/creditdebitnote/approval')->exists()) {
            $approvalRow = [
                'parent_id' => $approvalGroupId,
                'name' => 'Credit/Debit Note Approval',
                'icon' => 'bx bx-buildings',
                'route_name' => 'creditdebitnoteapprovals',
                'route_path' => '/creditdebitnote/approval',
                'menu_color' => '#6a0dad',
                'sort_order' => (int) DB::table('menus')->where('parent_id', $approvalGroupId)->max('sort_order') + 1,
                'is_hidden' => 0,
                'is_active' => 1,
                'is_permission' => 0,
                'type' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($hasAdminColumn) {
                $approvalRow['is_admin'] = 0;
            }

            DB::table('menus')->insert($approvalRow);
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $paths = [
            '/creditdebitnote',
            '/creditdebitnote/add',
            '/creditdebitnote/:id/edit',
            '/creditdebitnote/:id/view',
            '/creditdebitnote/delete',
            '/creditdebitnote/:id/approve',
            '/creditdebitnote/:id/reject',
            '/creditdebitnote/approval',
        ];

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
