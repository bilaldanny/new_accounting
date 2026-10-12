<?php

namespace App\Support;

use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Starter permission sets for the roles a company usually creates (Accountant, Cashier, Sales Representative, Manager,
 * Warehouse Keeper). The app ships no such roles: each company defines its own and ticks menus on the Role Permission
 * screen, so these are the defaults that screen would otherwise need ticked by hand.
 *
 * A `manage` path grants that menu and every menu under it (add / edit / delete / import ...); a `view` path grants only
 * that menu. Approval, approve and reject menus are never part of a path's children unless the preset lists `approvals`,
 * so the person who records a document cannot also approve it.
 */
final class RolePresets
{
    public const APPROVAL_PATTERN = '#(^|/)(approval|approve|reject)$|^/assetapproval$#';

    /**
     * @var array<string, array{manage: list<string>, view: list<string>, exclude: list<string>, approvals: bool}>
     */
    public const PRESETS = [
        'Accountant' => [
            'manage' => [
                '/chart-of-account', '/opening-balance', '/journalentry', '/acpayment', '/expense', '/deposit', '/fundtransfer',
                '/creditdebitnote', '/bankreconciliation', '/assetcategory', '/fixedasset', '/depreciation', '/costcenter', '/budget',
                '/taxexemption', '/tax', '/financialyear', '/landedcost', '/period-lock', '/supplierpayment',
                '/pettycash', '/chequeprint', '/bankinstrument', '/loan', '/taxpayment', '/paymentterm', '/allocation', '/journalschedule', '/baddebt', '/dunning', '/project', '/foreigncurrency', '/interbranch', '/hrm', '/report/production-summary', '/report/wip-report', '/report/material-variance', '/report/scrap-report',
            ],
            'view' => [
                '/customer', '/supplier', '/bank', '/report/account-ledger', '/report/ledger', '/report/trial-balance', '/report/balance-sheet',
                '/report/profit-loss', '/report/cash-flow', '/report/tax', '/report/budget-vs-actual', '/report/cost-center-analysis',
                '/report/financial-ratios', '/report/vouchers', '/report/expense', '/report/payment-account', '/report/customer-outstanding',
                '/report/supplier-outstanding', '/report/customer-aging', '/report/supplier-aging', '/report/payment-age', '/cashflowforecast', '/valuation',
                '/report/profit-loss-comparative', '/report/balance-sheet-comparative', '/report/cash-flow-indirect', '/report/withholding-tax', '/report/tax-exemption',
                '/report/tax-liability', '/report/advance', '/report/stock-take', '/report/fbr-audit-trail', '/report/payroll-register', '/report/statutory-liability', '/report/attendance-summary', '/report/loan-balances', '/analytics', '/reportbuilder', '/setup', '/help',
            ],
            'exclude' => ['/period-lock/reopen', '/period-lock/override'],
            'approvals' => false,
        ],
        'Cashier' => [
            'manage' => ['/sell/pos', '/sell/payment/add', '/posshift', '/cashcollection', '/giftcard/redeem', '/loyalty/redeem'],
            'view' => ['/product', '/customer', '/report/register', '/posoffline', '/help'],
            'exclude' => ['/cashcollection/reverse', '/cashcollection/cancel', '/cashcollection/refund'],
            'approvals' => false,
        ],
        'Sales Representative' => [
            'manage' => ['/leads', '/opportunities', '/activities', '/pipeline', '/customer', '/sell', '/warrantyclaim'],
            'view' => ['/leadsources', '/pipelinestages', '/crmanalytics', '/product', '/report/sell', '/report/product-sell', '/help'],
            'exclude' => ['/sell/pos', '/sell/price-override'],
            'approvals' => false,
        ],
        'Manager' => [
            'manage' => ['/posoffline'],
            'view' => [
                '/purchase', '/sell', '/stocktransfer', '/stockadjustment', '/purchase/return', '/sell/return', '/cashcollection', '/pricelist', '/fixedasset',
                '/journalentry', '/acpayment', '/expense', '/deposit', '/fundtransfer', '/creditdebitnote', '/purchaserequisition', '/portalrequests', '/reordersuggestion', '/rma',
                '/cashflowforecast', '/loan', '/bankinstrument', '/approvalcenter', '/support', '/documentcenter', '/hrm', '/manufacturing',
                '/analytics', '/reportbuilder', '/setup', '/help', '/report/sales-by-salesperson', '/report/sales-by-warehouse', '/report/sales-by-payment-method', '/report/sales-forecast',
                '/report/commission-report', '/report/target-achievement', '/report/profit-by-customer', '/report/profit-by-branch', '/report/profit-by-salesperson',
                '/report/profit-by-warehouse',
            ],
            'exclude' => [],
            'approvals' => true,
        ],
        'Warehouse Keeper' => [
            'manage' => [
                '/warehouse', '/warehouselocation', '/stocktracking', '/stocktake', '/stockadjustment', '/stocktransfer', '/receivingnote',
                '/issuenote', '/openingstock', '/lowstock', '/printlabel', '/productbom', '/stockreservation', '/warehousetask', '/stockwriteoff', '/qualityinspection', '/inboundshipment', '/manufacturing',
            ],
            'view' => [
                '/product', '/report/warehouse-stock', '/report/warehouse-usage', '/report/stock-movement-history', '/report/serial-traceability',
                '/report/batch-expiry', '/report/abc-analysis', '/report/stock-ageing', '/report/stock', '/report/stock-adjustment', '/report/stock-transfer', '/report/backorder', '/report/fsn', '/report/stock-take', '/help',
            ],
            'exclude' => [],
            'approvals' => false,
        ],
    ];

    /**
     * The preset a role name stands for, e.g. "sales rep" is not one but "Sales Representative" and "warehousekeeper" are.
     */
    public static function presetFor(?string $roleName): ?string
    {
        $wanted = Role::normalizeName($roleName);

        foreach (array_keys(self::PRESETS) as $preset) {
            if (Role::normalizeName($preset) === $wanted) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public static function menuIds(string $preset): array
    {
        $definition = self::PRESETS[$preset];
        $menus = DB::table('menus')->where('is_active', 1)->whereNotNull('route_path')->where('route_path', '!=', '')->pluck('route_path', 'id');
        $ids = [];

        foreach ($menus as $id => $path) {
            $path = (string) $path;
            $isApproval = preg_match(self::APPROVAL_PATTERN, $path) === 1;

            if ($definition['approvals'] && $isApproval) {
                $ids[] = (int) $id;

                continue;
            }

            if (in_array($path, $definition['exclude'], true)) {
                continue;
            }

            if (in_array($path, $definition['view'], true)) {
                $ids[] = (int) $id;

                continue;
            }

            if ($isApproval) {
                continue;
            }

            foreach ($definition['manage'] as $base) {
                if ($path === $base || str_starts_with($path, $base.'/')) {
                    $ids[] = (int) $id;

                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * Creates the starter roles of a company branch and turns their menus on (what `roles:sync-presets` does). A role that
     * already exists is only topped up. With `$dryRun` nothing is written and the menu count is what would be granted.
     *
     * @param  list<string>  $only  preset names; empty means all of them
     * @return array<string, array{created: bool, menus: int}> keyed by preset name
     */
    public static function sync(int $companyId, int $branchId, array $only = [], bool $dryRun = false): array
    {
        $only = array_map(Role::normalizeName(...), $only);
        $result = [];

        foreach (array_keys(self::PRESETS) as $preset) {
            if ($only !== [] && ! in_array(Role::normalizeName($preset), $only, true)) {
                continue;
            }

            $role = Role::query()->where('company_id', $companyId)->where('branch_id', $branchId)
                ->whereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [Role::normalizeName($preset)])->first();

            if ($dryRun) {
                $result[$preset] = ['created' => $role === null, 'menus' => count(self::menuIds($preset))];

                continue;
            }

            $created = $role === null;
            $role ??= Role::query()->create(['name' => $preset, 'company_id' => $companyId, 'branch_id' => $branchId, 'is_active' => true]);
            $result[$preset] = ['created' => $created, 'menus' => self::grant((int) $role->id, $companyId, $branchId, $preset)];
        }

        return $result;
    }

    /**
     * Turns the preset's menus on for a role in its own company / branch scope. Existing rows are switched on, nothing is
     * ever switched off.
     */
    public static function grant(int $roleId, ?int $companyId, ?int $branchId, string $preset): int
    {
        $ids = self::menuIds($preset);

        foreach ($ids as $menuId) {
            DB::table('permissions')->updateOrInsert(
                ['role_id' => $roleId, 'menu_id' => $menuId, 'company_id' => $companyId, 'branch_id' => $branchId, 'department_id' => null],
                ['status' => 1, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        self::flushMenuCaches($roleId);

        return count($ids);
    }

    public static function revoke(int $roleId, string $preset): int
    {
        $removed = DB::table('permissions')->where('role_id', $roleId)->whereIn('menu_id', self::menuIds($preset))->delete();

        self::flushMenuCaches($roleId);

        return $removed;
    }

    public static function flushMenuCaches(int $roleId): void
    {
        Cache::forget("user_menu_permissions_tree:{$roleId}");
        Cache::forget("user_permission_paths:{$roleId}");
        Cache::forget("user_menu_permissions:{$roleId}");
    }
}
