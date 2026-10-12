<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Wave 7: the HR and Payroll hub, its manage / attendance / payroll permissions and the approvals of leave and payroll
 * (named so the role presets keep approvals out of the non-manager roles), and the payroll reports, granted to the companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/user', 'HR and Payroll', '/hrm', 'hrm', 'Users', false],
            ['/hrm', 'Manage Employees and Settings', '/hrm/manage', 'hrm.manage', 'Grid', true],
            ['/hrm', 'Attendance and Leave', '/hrm/attendance', 'hrm.attendance', 'Grid', true],
            ['/hrm', 'Approve Leave', '/hrm/leave/approve', 'hrm.leave.approve', 'Grid', true],
            ['/hrm', 'Prepare Payroll', '/hrm/payroll', 'hrm.payroll', 'Grid', true],
            ['/hrm', 'Approve and Post Payroll', '/hrm/payroll/approve', 'hrm.payroll.approve', 'Grid', true],
            ['sibling:/report/stock', 'Payroll Register', '/report/payroll-register', 'report.payroll-register', 'FileBarChart', false],
            ['sibling:/report/stock', 'Statutory Liability', '/report/statutory-liability', 'report.statutory-liability', 'FileBarChart', false],
            ['sibling:/report/stock', 'Attendance Summary', '/report/attendance-summary', 'report.attendance-summary', 'FileBarChart', false],
            ['sibling:/report/stock', 'Employee Loan Balances', '/report/loan-balances', 'report.loan-balances', 'FileBarChart', false],
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
