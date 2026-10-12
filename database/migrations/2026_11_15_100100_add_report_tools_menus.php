<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Wave 4: the analytics reports (next to the Stock report), the Analytics page and the Report Builder with its manage
 * permission (which also covers scheduled reports), granted to the global companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/report/stock', 'Comparative Profit & Loss', '/report/profit-loss-comparative', 'report.profit-loss-comparative', 'FileBarChart', false],
            ['sibling:/report/stock', 'Comparative Balance Sheet', '/report/balance-sheet-comparative', 'report.balance-sheet-comparative', 'FileBarChart', false],
            ['sibling:/report/stock', 'Cash Flow Statement (Indirect)', '/report/cash-flow-indirect', 'report.cash-flow-indirect', 'FileBarChart', false],
            ['sibling:/report/stock', 'Sales by Salesperson', '/report/sales-by-salesperson', 'report.sales-by-salesperson', 'FileBarChart', false],
            ['sibling:/report/stock', 'Sales by Warehouse', '/report/sales-by-warehouse', 'report.sales-by-warehouse', 'FileBarChart', false],
            ['sibling:/report/stock', 'Sales by Payment Method', '/report/sales-by-payment-method', 'report.sales-by-payment-method', 'FileBarChart', false],
            ['sibling:/report/stock', 'Profit by Customer', '/report/profit-by-customer', 'report.profit-by-customer', 'FileBarChart', false],
            ['sibling:/report/stock', 'Profit by Branch', '/report/profit-by-branch', 'report.profit-by-branch', 'FileBarChart', false],
            ['sibling:/report/stock', 'Profit by Salesperson', '/report/profit-by-salesperson', 'report.profit-by-salesperson', 'FileBarChart', false],
            ['sibling:/report/stock', 'Profit by Warehouse', '/report/profit-by-warehouse', 'report.profit-by-warehouse', 'FileBarChart', false],
            ['sibling:/report/stock', 'Commission Report', '/report/commission-report', 'report.commission-report', 'FileBarChart', false],
            ['sibling:/report/stock', 'Target Achievement', '/report/target-achievement', 'report.target-achievement', 'FileBarChart', false],
            ['sibling:/report/stock', 'Sales Forecast', '/report/sales-forecast', 'report.sales-forecast', 'FileBarChart', false],
            ['sibling:/report/stock', 'Stock Take Variance', '/report/stock-take', 'report.stock-take', 'FileBarChart', false],
            ['sibling:/report/stock', 'Advances Held', '/report/advance', 'report.advance', 'FileBarChart', false],
            ['sibling:/report/stock', 'Withholding Tax', '/report/withholding-tax', 'report.withholding-tax', 'FileBarChart', false],
            ['sibling:/report/stock', 'Tax Exemptions Used', '/report/tax-exemption', 'report.tax-exemption', 'FileBarChart', false],
            ['sibling:/report/stock', 'Tax Liability Reconciliation', '/report/tax-liability', 'report.tax-liability', 'FileBarChart', false],
            ['sibling:/report/stock', 'FBR Submission Audit Trail', '/report/fbr-audit-trail', 'report.fbr-audit-trail', 'FileBarChart', false],
            ['sibling:/report/stock', 'Analytics', '/analytics', 'analytics', 'BarChart3', false],
            ['sibling:/report/stock', 'Report Builder', '/reportbuilder', 'reportbuilder', 'Table2', false],
            ['/reportbuilder', 'Manage Custom and Scheduled Reports', '/reportbuilder/manage', 'reportbuilder.manage', 'Grid', true],
        ];
    }

    public function up(): void
    {
        MenuMigration::install($this->rows());
    }

    public function down(): void
    {
        MenuMigration::remove(array_column($this->rows(), 2));
    }
};
