<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Foreign Currency vouchers (switch, dated rates, settlement), in the group of Bank Reconciliation, granted to the
 * global companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/bankreconciliation', 'Foreign Currency', '/foreigncurrency', 'foreigncurrency', 'Coins', false],
            ['/foreigncurrency', 'Foreign Currency Settings', '/foreigncurrency/settings', 'foreigncurrency.settings', 'Grid', true],
            ['/foreigncurrency', 'Add Dated Exchange Rate', '/foreigncurrency/rates', 'foreigncurrency.rates', 'Grid', true],
            ['/foreigncurrency', 'Settle Foreign Currency Line', '/foreigncurrency/settle', 'foreigncurrency.settle', 'Grid', true],
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
