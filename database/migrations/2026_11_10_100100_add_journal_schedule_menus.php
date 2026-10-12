<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Journal Schedules (recurring and reversing journals, recurring expenses, amortization, accruals), in the group of
 * Bank Reconciliation, granted to the global companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/bankreconciliation', 'Journal Schedules', '/journalschedule', 'journalschedule', 'Repeat', false],
            ['/journalschedule', 'Add Journal Schedule', '/journalschedule/add', 'journalschedule.add', 'Grid', true],
            ['/journalschedule', 'Edit Journal Schedule', '/journalschedule/:id/edit', 'journalschedule.edit', 'Grid', true],
            ['/journalschedule', 'Delete Journal Schedule', '/journalschedule/delete', 'journalschedule.delete', 'Grid', true],
            ['/journalschedule', 'Run Journal Schedules', '/journalschedule/run', 'journalschedule.run', 'Grid', true],
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
