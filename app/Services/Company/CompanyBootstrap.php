<?php

namespace App\Services\Company;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What a company needs on its first day to take a sale, a purchase or a journal without somebody building the set-up by hand: an open
 * financial year, a chart of accounts with the posting keys mapped to it, the first units, brand, category and item type, and an
 * admin role that can open the application.
 *
 * It is used when a company is created (public registration and the Company page), and every step only fills what is absent, so
 * running it again, or on a company that has been in use for years, changes nothing: a company that already has any account below
 * the six top level ones keeps its chart and its mappings exactly as they are.
 */
class CompanyBootstrap
{
    /**
     * Ledger accounts of the starting chart: key => [code, name, parent key, nature, profit and loss?]. Parents are the six top level
     * accounts (`top:200-00000`) or an earlier row.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: string}> 5 is `c` (group) or `t` (posting account)
     */
    public const CHART = [
        'current_assets' => ['210-00000', 'Current Assets', 'top:200-00000', 'dr', false, 'c'],
        'receivables' => ['211-00000', 'Trade Receivables', 'current_assets', 'dr', false, 'c'],
        'cash_bank' => ['212-00000', 'Cash and Bank', 'current_assets', 'dr', false, 'c'],
        'cash' => ['212-00001', 'Cash in Hand', 'cash_bank', 'dr', false, 't'],
        'bank' => ['212-00002', 'Bank Account', 'cash_bank', 'dr', false, 't'],
        'inventory_group' => ['213-00000', 'Inventory', 'current_assets', 'dr', false, 'c'],
        'inventory' => ['213-00001', 'Stock in Trade', 'inventory_group', 'dr', false, 't'],
        'tax_asset_group' => ['214-00000', 'Taxes Recoverable', 'current_assets', 'dr', false, 'c'],
        'input_tax' => ['214-00001', 'Sales Tax Input', 'tax_asset_group', 'dr', false, 't'],
        'staff_group' => ['215-00000', 'Staff Receivables', 'current_assets', 'dr', false, 'c'],
        'staff_loans' => ['215-00001', 'Staff Loans and Advances', 'staff_group', 'dr', false, 't'],
        'current_liabilities' => ['310-00000', 'Current Liabilities', 'top:300-00000', 'cr', false, 'c'],
        'payables' => ['311-00000', 'Trade Payables', 'current_liabilities', 'cr', false, 'c'],
        'tax_liability_group' => ['312-00000', 'Taxes and Contributions Payable', 'current_liabilities', 'cr', false, 'c'],
        'output_tax' => ['312-00001', 'Sales Tax Output', 'tax_liability_group', 'cr', false, 't'],
        'income_tax_payable' => ['312-00002', 'Income Tax Payable (salaries)', 'tax_liability_group', 'cr', false, 't'],
        'statutory_payable' => ['312-00003', 'Statutory Contributions Payable', 'tax_liability_group', 'cr', false, 't'],
        'accrued_group' => ['313-00000', 'Accrued Liabilities', 'current_liabilities', 'cr', false, 'c'],
        'salaries_payable' => ['313-00001', 'Salaries Payable', 'accrued_group', 'cr', false, 't'],
        'advances_group' => ['314-00000', 'Customer Advances', 'current_liabilities', 'cr', false, 'c'],
        'customer_advance' => ['314-00001', 'Advances from Customers', 'advances_group', 'cr', false, 't'],
        'capital_group' => ['110-00000', 'Capital and Reserves', 'top:100-00000', 'cr', false, 'c'],
        'capital' => ['110-00001', "Owner's Capital", 'capital_group', 'cr', false, 't'],
        'pnl' => ['110-00002', 'Retained Earnings', 'capital_group', 'cr', false, 't'],
        'sales_group' => ['510-00000', 'Sales', 'top:500-00000', 'cr', true, 'c'],
        'local_sales' => ['510-00001', 'Local Sales', 'sales_group', 'cr', true, 't'],
        'export_sales' => ['510-00002', 'Export Sales', 'sales_group', 'cr', true, 't'],
        'cogs_group' => ['610-00000', 'Cost of Sales', 'top:600-00000', 'dr', true, 'c'],
        'cogs' => ['610-00001', 'Cost of Goods Sold', 'cogs_group', 'dr', true, 't'],
        'purchases' => ['610-00002', 'Purchases', 'cogs_group', 'dr', true, 't'],
        'operating_group' => ['410-00000', 'Operating Expenses', 'top:400-00000', 'dr', true, 'c'],
        'salaries_expense' => ['410-00001', 'Salaries and Wages', 'operating_group', 'dr', true, 't'],
        'rent' => ['410-00002', 'Shop Rent', 'operating_group', 'dr', true, 't'],
        'utilities' => ['410-00003', 'Electricity and Utilities', 'operating_group', 'dr', true, 't'],
    ];

    /** Posting key => chart key it is mapped to (the group for customers, suppliers and banks, whose accounts are created below it). */
    public const MAPPING = [
        'customer' => 'receivables', 'supplier' => 'payables', 'customeradvance' => 'customer_advance', 'bank' => 'cash_bank', 'cash' => 'cash',
        'purchase' => 'purchases', 'importpurchase' => 'purchases', 'localpurchase' => 'purchases', 'inputtax' => 'input_tax',
        'sale' => 'local_sales', 'localsales' => 'local_sales', 'exportsale' => 'export_sales', 'outputtax' => 'output_tax', 'pnl' => 'pnl',
    ];

    private const TOP_LEVEL = ['100-00000', '200-00000', '300-00000', '400-00000', '500-00000', '600-00000'];

    /** The two accounts the withholding set-up adds when a branch is created; they are not a chart somebody built. */
    private const WITHHOLDING = ['200-00001', '300-00001'];

    private const TOP_MAPPING = ['equity' => '100-00000', 'assets' => '200-00000', 'liability' => '300-00000', 'expenses' => '400-00000', 'revenue' => '500-00000'];

    /** Paths that belong to the platform operator, not to a company. */
    private const PLATFORM_PATHS = '#^/(subscriptionplans|tenants|subscriptioninvoices|subscriptionbilling|saasadmin|software/setting|company/add|company/delete|company/restore)#';

    /**
     * @return array{financial_year: bool, chart: bool, defaults: bool, role_menus: int}
     */
    public function run(Company $company, Branch $branch, ?Role $adminRole = null, ?CarbonImmutable $today = null): array
    {
        $companyId = (int) $company->id;
        $branchId = (int) $branch->id;

        return [
            'financial_year' => $this->financialYear($companyId, $today ?? CarbonImmutable::now()),
            'chart' => $this->chartOfAccounts($companyId, $branchId),
            'defaults' => $this->catalogueDefaults($companyId),
            'role_menus' => $adminRole === null ? 0 : $this->adminRoleMenus($adminRole, $companyId),
        ];
    }

    /**
     * The chart's account ids by key, found by code (empty keys for a company with a different chart).
     *
     * @return array<string, int>
     */
    public function accountIds(int $companyId, int $branchId): array
    {
        $byCode = ChartOfAccount::query()->where('company_id', $companyId)->where('branch_id', $branchId)->pluck('id', 'code');
        $ids = [];

        foreach (self::CHART as $key => $row) {
            if (isset($byCode[$row[0]])) {
                $ids[$key] = (int) $byCode[$row[0]];
            }
        }

        return $ids;
    }

    private function financialYear(int $companyId, CarbonImmutable $today): bool
    {
        if (FinancialYear::query()->where('company_id', $companyId)->exists()) {
            return false;
        }

        // Pakistan's financial year runs July to June
        $startYear = $today->month >= 7 ? $today->year : $today->year - 1;

        FinancialYear::query()->create([
            'company_id' => $companyId, 'name' => "FY {$startYear}-".($startYear + 1), 'start_date' => "{$startYear}-07-01", 'end_date' => ($startYear + 1).'-06-30', 'status' => true,
        ]);

        return true;
    }

    private function chartOfAccounts(int $companyId, int $branchId): bool
    {
        $existing = ChartOfAccount::query()->where('company_id', $companyId)->where('branch_id', $branchId)->get(['id', 'code']);
        $top = $existing->whereIn('code', self::TOP_LEVEL)->pluck('id', 'code');

        // an account below the top level (other than the withholding ones) means a chart was built or imported: leave it and its mappings alone
        if ($existing->whereNotIn('code', array_merge(self::TOP_LEVEL, self::WITHHOLDING))->isNotEmpty() || $top->count() < count(self::TOP_LEVEL)) {
            return false;
        }

        $ids = [];

        foreach (self::CHART as $key => [$code, $name, $parent, $nature, $profitAndLoss, $type]) {
            $parentId = str_starts_with($parent, 'top:') ? (int) $top[substr($parent, 4)] : $ids[$parent];
            $ids[$key] = (int) ChartOfAccount::query()->create([
                'company_id' => $companyId, 'branch_id' => $branchId, 'parent_id' => $parentId, 'code' => $code, 'name' => $name, 'acc_type' => $type,
                'acc_nature' => $nature, 'pl' => $profitAndLoss, 'bs' => ! $profitAndLoss, 'active' => true,
            ])->id;
        }

        $values = [];

        foreach (self::TOP_MAPPING as $key => $code) {
            $values[$key] = (int) $top[$code];
        }

        foreach (self::MAPPING as $key => $chartKey) {
            $values[$key] = $ids[$chartKey];
        }

        foreach ($values as $key => $accountId) {
            // only an empty mapping is filled, and a missing one is created
            $row = DB::table('chart_of_account_mappings')->where('company_id', $companyId)->where('branch_id', $branchId)->where('key', $key)->first();

            if ($row === null) {
                DB::table('chart_of_account_mappings')->insert([
                    'company_id' => $companyId, 'branch_id' => $branchId, 'name' => ucwords(str_replace('_', ' ', $key)), 'key' => $key, 'value' => (string) $accountId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif ($row->value === null || $row->value === '') {
                DB::table('chart_of_account_mappings')->where('id', $row->id)->update(['value' => (string) $accountId, 'updated_at' => now()]);
            }
        }

        return true;
    }

    /**
     * The first unit, brand, category and item type, which a product form requires before the first product can be saved.
     */
    private function catalogueDefaults(int $companyId): bool
    {
        $made = false;
        $now = ['created_at' => now(), 'updated_at' => now()];

        $make = function (string $table, array $row) use ($companyId, &$made, $now): void {
            if (! DB::getSchemaBuilder()->hasTable($table) || DB::table($table)->where('company_id', $companyId)->exists()) {
                return;
            }

            DB::table($table)->insert($row + ['company_id' => $companyId] + $now);
            $made = true;
        };

        $make('units', ['name' => 'Piece', 'short_name' => 'pc', 'active' => 1]);
        $make('brands', ['name' => 'General', 'active' => 1]);
        $make('categories', ['name' => 'General', 'active' => 1]);
        $make('item_types', ['name' => 'Goods', 'active' => 1]);

        return $made;
    }

    /**
     * An admin role that belongs to the company itself (the application then had no shared `companyadmin` role to use) starts with no
     * menu and would open to an empty application: it is given every menu a company may use. The shared role is left to the menu
     * migrations, which keep granting it.
     */
    private function adminRoleMenus(Role $role, int $companyId): int
    {
        if ((int) $role->company_id !== $companyId || DB::table('permissions')->where('role_id', $role->id)->exists()) {
            return 0;
        }

        $granted = 0;

        foreach (DB::table('menus')->where('is_active', 1)->where('is_admin', 0)->get(['id', 'route_path']) as $menu) {
            if ($menu->route_path !== null && preg_match(self::PLATFORM_PATHS, (string) $menu->route_path) === 1) {
                continue;
            }

            DB::table('permissions')->insert([
                'role_id' => $role->id, 'menu_id' => $menu->id, 'company_id' => null, 'branch_id' => null, 'department_id' => null, 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $granted++;
        }

        foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
            Cache::forget("{$key}:{$role->id}");
        }

        return $granted;
    }
}
