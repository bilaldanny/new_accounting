<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for the offline POS review queue: the sales a terminal rang up offline, with the ones the server parked for a
 * supervisor, in the group of POS Shifts, granted to the global companyadmin role. The offline terminal itself is a page of the POS
 * menu (/sell/pos/offline) and needs no row of its own.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/posshift', 'Offline POS Sales', '/posoffline', 'posoffline', 'WifiOff', false],
            ['/posoffline', 'Resolve Offline POS Sale', '/posoffline/resolve', 'posoffline.resolve', 'Grid', true],
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
