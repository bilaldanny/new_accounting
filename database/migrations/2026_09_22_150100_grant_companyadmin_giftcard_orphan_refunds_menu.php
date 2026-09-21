<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Grants the default company admin role (`companyadmin`, the global role every new company's admin is
 * created with) the Gift Card Orphaned Refunds page row: 1 `permissions` row.
 *
 * 2026_09_22_150000_add_giftcard_orphan_refunds_menu only adds the menu row; roles get `permissions` rows through
 * the Role Permission screen, so without this the page is not open to the company admin. Superadmin is always
 * allowed and other roles are left alone.
 *
 * Follows 2026_09_21_210200_grant_companyadmin_giftcard_menu: idempotent (an existing row is re-enabled, a missing
 * one added), a menu row that does not exist is skipped, and `down()` removes only this row.
 */
return new class extends Migration
{
    /**
     * Exact route paths of the menu rows granted.
     *
     * @var list<string>
     */
    private const PATHS = [
        '/giftcard/orphan-refunds',
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
