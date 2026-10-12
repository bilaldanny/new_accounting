<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Permissions to pay back an unused customer advance (cash collection) and to take back an unused supplier advance
 * (supplier payment), granted to the global companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['/cashcollection', 'Refund Cash Collection Advance', '/cashcollection/refund', 'cashcollection.refund', 'Grid', true],
            ['/supplierpayment', 'Refund Supplier Advance', '/supplierpayment/refund', 'supplierpayment.refund', 'Grid', true],
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
