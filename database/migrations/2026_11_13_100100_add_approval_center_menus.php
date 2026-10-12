<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for the Approval Center (inbox, outbox, history, delegation) and its manage permission (workflows, settings, discount
 * code approval), granted to the global companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/cashcollection/approval', 'Approval Center', '/approvalcenter', 'approvalcenter', 'Workflow', false],
            ['/approvalcenter', 'Manage Approval Workflows', '/approvalcenter/manage', 'approvalcenter.manage', 'Grid', true],
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
