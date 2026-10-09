<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Grants companyadmin the whole Subscriptions group: unlike Module 17's Billing group (platform-owner,
 * superadmin-only), the Customer Subscription Module is a tenant's own tool for billing ITS customers,
 * so a tenant's own admin gets full access (plans, contracts, invoices, analytics).
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const PATHS = [
        '/customersubscriptionplans',
        '/customersubscriptionplans/add',
        '/customersubscriptionplans/:id/edit',
        '/customersubscriptionplans/delete',
        '/customersubscriptionplans/restore',
        '/customersubscriptions',
        '/customersubscriptions/add',
        '/customersubscriptions/:id/edit',
        '/customersubscriptioninvoices',
        '/customersubscriptioninvoices/add',
        '/customersubscriptioninvoices/:id/edit',
        '/customersubscriptionanalytics',
    ];

    public function up(): void
    {
        $roleId = $this->companyAdminRoleId();
        $menuIds = $this->menuIds();

        if ($roleId === null || $menuIds === []) {
            return;
        }

        $existing = DB::table('permissions')
            ->where('role_id', $roleId)
            ->whereIn('menu_id', $menuIds)
            ->pluck('menu_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($existing !== []) {
            DB::table('permissions')
                ->where('role_id', $roleId)
                ->whereIn('menu_id', $existing)
                ->update(['status' => 1, 'updated_at' => now()]);
        }

        $missing = array_values(array_diff($menuIds, $existing));

        if ($missing !== []) {
            DB::table('permissions')->insert(array_map(fn (int $menuId): array => [
                'company_id' => null,
                'branch_id' => null,
                'department_id' => null,
                'role_id' => $roleId,
                'menu_id' => $menuId,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ], $missing));
        }

        $this->flushMenuCaches($roleId);
    }

    public function down(): void
    {
        $roleId = $this->companyAdminRoleId();
        $menuIds = $this->menuIds();

        if ($roleId === null || $menuIds === []) {
            return;
        }

        DB::table('permissions')->where('role_id', $roleId)->whereIn('menu_id', $menuIds)->delete();

        $this->flushMenuCaches($roleId);
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

    /**
     * @return list<int>
     */
    private function menuIds(): array
    {
        return DB::table('menus')
            ->whereNull('deleted_at')
            ->whereIn('route_path', self::PATHS)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function flushMenuCaches(int $roleId): void
    {
        Cache::forget("user_menu_permissions_tree:{$roleId}");
        Cache::forget("user_permission_paths:{$roleId}");
        Cache::forget("user_menu_permissions:{$roleId}");
    }
};
