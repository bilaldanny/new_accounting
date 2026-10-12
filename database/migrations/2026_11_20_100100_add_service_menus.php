<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Wave 8 repair and field service: the Service and Field Jobs board, the manage permission and the technician's own page
 * (My Jobs, the mobile page of the field app), granted to the companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/manufacturing', 'Service and Field Jobs', '/service', 'service', 'Wrench', false],
            ['/service', 'Create, Assign and Invoice Jobs', '/service/manage', 'service.manage', 'Grid', true],
            ['/service', 'My Jobs', '/service/work', 'service.work', 'Grid', true],
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
