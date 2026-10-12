<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for the wave 1b dashboard tools, granted to the companyadmin role: who may set dashboard layouts for the roles of the
 * company and who may set up and send the daily business summary.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/user', 'Dashboard Layouts by Role', '/dashboard/layouts', 'dashboard.layouts', 'Grid', true],
            ['sibling:/user', 'Daily Business Summary', '/dashboard/summary', 'dashboard.summary', 'Grid', true],
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
