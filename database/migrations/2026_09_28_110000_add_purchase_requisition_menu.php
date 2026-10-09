<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu rows for Purchase Requisition: a visible `/purchaserequisition` list row in the Purchase group
 * (next to Purchase, Receiving Note, Purchase Return, Purchase Payment, Transporter), its hidden
 * add/edit/view/delete rows, its hidden approve/reject rows (the keys
 * PurchaseRequisitionApprovalController checks), and a visible `/purchaserequisition/approval` list
 * row in the Approval group, the sibling of Purchase/Sell/Journal Entry/... Approval.
 *
 * Permissions are not granted here: roles get the rows from the Role Permission screen (except
 * companyadmin, granted by 2026_09_28_120000_grant_companyadmin_purchase_requisition_menu).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route_path', '/purchaserequisition')->exists()) {
            return;
        }

        $hasAdminColumn = Schema::hasColumn('menus', 'is_admin');
        $purchaseGroupId = DB::table('menus')->where('route_path', '/purchase')->value('parent_id');
        $sortOrder = (int) DB::table('menus')->where('parent_id', $purchaseGroupId)->max('sort_order');

        $parent = [
            'parent_id' => $purchaseGroupId,
            'name' => 'Purchase Requisition',
            'icon' => 'bx bx-buildings',
            'route_name' => '',
            'route_path' => '/purchaserequisition',
            'menu_color' => '#6a0dad',
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
            ['name' => 'Add Purchase Requisition', 'route_name' => '', 'route_path' => '/purchaserequisition/add'],
            ['name' => 'Edit Purchase Requisition', 'route_name' => 'editpurchaserequisition', 'route_path' => '/purchaserequisition/:id/edit'],
            ['name' => 'View Purchase Requisition', 'route_name' => 'viewpurchaserequisition', 'route_path' => '/purchaserequisition/:id/view'],
            ['name' => 'Delete Purchase Requisition', 'route_name' => 'purchaserequisitions.destroy', 'route_path' => '/purchaserequisition/delete'],
            ['name' => 'Purchase Requisition Approve', 'route_name' => 'purchaserequisitionapproval', 'route_path' => '/purchaserequisition/:id/approve'],
            ['name' => 'Purchase Requisition Reject', 'route_name' => 'purchaserequisitionreject', 'route_path' => '/purchaserequisition/:id/reject'],
        ];

        foreach ($children as $index => $child) {
            $payload = [
                'parent_id' => $parentId,
                'name' => $child['name'],
                'icon' => 'Grid',
                'route_name' => $child['route_name'],
                'route_path' => $child['route_path'],
                'menu_color' => '#6a0dad',
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

        if ($approvalGroupId !== null && ! DB::table('menus')->where('route_path', '/purchaserequisition/approval')->exists()) {
            $approvalRow = [
                'parent_id' => $approvalGroupId,
                'name' => 'Purchase Requisition Approval',
                'icon' => 'bx bx-buildings',
                'route_name' => 'purchaserequisitionapprovals',
                'route_path' => '/purchaserequisition/approval',
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
            '/purchaserequisition',
            '/purchaserequisition/add',
            '/purchaserequisition/:id/edit',
            '/purchaserequisition/:id/view',
            '/purchaserequisition/delete',
            '/purchaserequisition/:id/approve',
            '/purchaserequisition/:id/reject',
            '/purchaserequisition/approval',
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
