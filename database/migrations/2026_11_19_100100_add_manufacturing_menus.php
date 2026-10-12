<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Wave 8 manufacturing: the Manufacturing hub, its manage / produce / approve permissions (named so the role presets
 * keep approval away from the roles that prepare), and the four production reports, granted to the companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/hrm', 'Manufacturing', '/manufacturing', 'manufacturing', 'Factory', false],
            ['/manufacturing', 'Manage BOMs, Plans and Settings', '/manufacturing/manage', 'manufacturing.manage', 'Grid', true],
            ['/manufacturing', 'Work Orders and Shop Floor', '/manufacturing/produce', 'manufacturing.produce', 'Grid', true],
            ['/manufacturing', 'Activate BOM and Release Work Orders', '/manufacturing/approve', 'manufacturing.approve', 'Grid', true],
            ['sibling:/report/stock', 'Production Summary', '/report/production-summary', 'report.production-summary', 'FileBarChart', false],
            ['sibling:/report/stock', 'Work in Progress', '/report/wip-report', 'report.wip-report', 'FileBarChart', false],
            ['sibling:/report/stock', 'Material Variance', '/report/material-variance', 'report.material-variance', 'FileBarChart', false],
            ['sibling:/report/stock', 'Production Scrap', '/report/scrap-report', 'report.scrap-report', 'FileBarChart', false],
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
