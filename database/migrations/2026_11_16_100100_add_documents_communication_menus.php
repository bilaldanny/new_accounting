<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Wave 5: the Document Center (templates, messages, reminders, delivery, custom fields) with its manage permission,
 * granted to the global companyadmin role. Printing, sending and attaching use the existing sale view / edit permissions.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/user', 'Document Center', '/documentcenter', 'documentcenter', 'FileText', false],
            ['/documentcenter', 'Manage Documents and Messages', '/documentcenter/manage', 'documentcenter.manage', 'Grid', true],
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
