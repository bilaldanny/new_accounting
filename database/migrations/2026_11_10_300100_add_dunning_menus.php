<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for invoice Dunning and collection management, in the group of Bank Reconciliation, granted to the global companyadmin
 * role. Editing the level templates, sending reminders and setting a customer's collection status are separate permissions.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/bankreconciliation', 'Dunning & Collections', '/dunning', 'dunning', 'BellRing', false],
            ['/dunning', 'Edit Dunning Levels', '/dunning/levels', 'dunning.levels', 'Grid', true],
            ['/dunning', 'Send Dunning Reminders', '/dunning/send', 'dunning.send', 'Grid', true],
            ['/dunning', 'Set Collection Status', '/dunning/case', 'dunning.case', 'Grid', true],
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
