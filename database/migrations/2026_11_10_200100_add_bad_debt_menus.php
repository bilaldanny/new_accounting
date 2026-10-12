<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Bad Debt (write-offs with approval, allowance for doubtful debts), in the group of Bank Reconciliation, granted to
 * the global companyadmin role. Approving a write-off is a separate permission from requesting one.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/bankreconciliation', 'Bad Debt', '/baddebt', 'baddebt', 'FileX', false],
            ['/baddebt', 'Request Bad Debt Write-off', '/baddebt/add', 'baddebt.add', 'Grid', true],
            ['/baddebt', 'Approve Bad Debt Write-off', '/baddebt/approve', 'baddebt.approve', 'Grid', true],
            ['/baddebt', 'Reverse Bad Debt Write-off', '/baddebt/reverse', 'baddebt.reverse', 'Grid', true],
            ['/baddebt', 'Bad Debt Settings', '/baddebt/settings', 'baddebt.settings', 'Grid', true],
            ['/baddebt', 'Set Allowance for Doubtful Debts', '/baddebt/provision', 'baddebt.provision', 'Grid', true],
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
