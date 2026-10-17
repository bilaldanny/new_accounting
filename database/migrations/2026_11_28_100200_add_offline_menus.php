<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * The Offline Entries page (the screens that keep working without a network and the queue they fill) and the hidden permission to
 * decide other people's conflicts. Granted to the company admin like any new page; the starter roles get the page from the presets.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/posoffline', 'Offline Entries', '/offline', 'offline', 'Grid', false],
            ['/offline', 'Resolve Offline Entries', '/offline/resolve', 'offline.resolve', 'Grid', true],
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
