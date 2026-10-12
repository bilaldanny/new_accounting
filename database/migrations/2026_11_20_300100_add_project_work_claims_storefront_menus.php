<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for the last Wave 8 modules: project milestones and tasks (under Projects), Expense Claims with add / approve / pay, and
 * the Online Shop with its manage permission, granted to the companyadmin role. The approve row is named so the role presets keep it
 * away from the roles that file claims.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['/project', 'Milestones and Tasks', '/project/work', 'project.work', 'Grid', true],
            ['/project', 'Manage Milestones and Tasks', '/project/work/manage', 'project.work.manage', 'Grid', true],
            ['sibling:/manufacturing', 'Expense Claims', '/expenseclaim', 'expenseclaim', 'Receipt', false],
            ['/expenseclaim', 'File an Expense Claim', '/expenseclaim/add', 'expenseclaim.add', 'Grid', true],
            ['/expenseclaim', 'Approve Expense Claims', '/expenseclaim/approve', 'expenseclaim.approve', 'Grid', true],
            ['/expenseclaim', 'Pay Expense Claims', '/expenseclaim/pay', 'expenseclaim.pay', 'Grid', true],
            ['sibling:/manufacturing', 'Online Shop', '/storefront', 'storefront', 'ShoppingBag', false],
            ['/storefront', 'Manage Online Shop and Orders', '/storefront/manage', 'storefront.manage', 'Grid', true],
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
