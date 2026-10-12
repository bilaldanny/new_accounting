<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Inventory Valuation (method, standard costs, cost recalculation) in the Inventory group, next to Inventory Close,
 * granted to the global companyadmin role. Changing the method, setting standard costs and recalculating are separate permissions.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/stocktake', 'Inventory Valuation', '/valuation', 'valuation', 'Calculator', false],
            ['/valuation', 'Change Valuation Method', '/valuation/method', 'valuation.method', 'Grid', true],
            ['/valuation', 'Set Standard Costs', '/valuation/standard', 'valuation.standard', 'Grid', true],
            ['/valuation', 'Recalculate Costs', '/valuation/recalculate', 'valuation.recalculate', 'Grid', true],
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
