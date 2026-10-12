<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Inter-Branch transfers and their consolidation, in the group of Bank Reconciliation, granted to the global
 * companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/bankreconciliation', 'Inter-Branch Transfers', '/interbranch', 'interbranch', 'ArrowLeftRight', false],
            ['/interbranch', 'Add Inter-Branch Transfer', '/interbranch/add', 'interbranch.add', 'Grid', true],
            ['/interbranch', 'Reverse Inter-Branch Transfer', '/interbranch/reverse', 'interbranch.reverse', 'Grid', true],
            ['/interbranch', 'Inter-Branch Accounts', '/interbranch/settings', 'interbranch.settings', 'Grid', true],
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
