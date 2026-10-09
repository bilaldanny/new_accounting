<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Grants companyadmin the Subscription Invoices page itself (view only — `scopeVisibleToCurrentUser`
 * already limits what they see to their own company's invoices), so a tenant can see its own bill.
 * Deliberately NOT granting the "add"/"edit" hidden rows here: generating, marking paid, and cancelling
 * a subscription invoice stay superadmin-only (SubscriptionInvoiceController's own `authorizeSuperadmin`
 * gate), so companyadmin gets read access without the controls that gate still blocks.
 */
return new class extends Migration
{
    private const PATH = '/subscriptioninvoices';

    public function up(): void
    {
        $roleId = $this->companyAdminRoleId();
        $menuId = DB::table('menus')->whereNull('deleted_at')->where('route_path', self::PATH)->value('id');

        if ($roleId === null || $menuId === null) {
            return;
        }

        $exists = DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $menuId)->exists();

        if ($exists) {
            DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $menuId)->update(['status' => 1, 'updated_at' => now()]);
        } else {
            DB::table('permissions')->insert([
                'company_id' => null,
                'branch_id' => null,
                'department_id' => null,
                'role_id' => $roleId,
                'menu_id' => $menuId,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->flushMenuCaches((int) $roleId);
    }

    public function down(): void
    {
        $roleId = $this->companyAdminRoleId();
        $menuId = DB::table('menus')->where('route_path', self::PATH)->value('id');

        if ($roleId === null || $menuId === null) {
            return;
        }

        DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $menuId)->delete();

        $this->flushMenuCaches((int) $roleId);
    }

    private function companyAdminRoleId(): ?int
    {
        $id = DB::table('roles')
            ->where('name', 'companyadmin')
            ->whereNull('company_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function flushMenuCaches(int $roleId): void
    {
        Cache::forget("user_menu_permissions_tree:{$roleId}");
        Cache::forget("user_permission_paths:{$roleId}");
        Cache::forget("user_menu_permissions:{$roleId}");
    }
};
