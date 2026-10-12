<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Wave 8 rental: the Rental hub, its manage permission (items, settings) and the book permission (bookings, pickup,
 * return), granted to the companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/manufacturing', 'Rental', '/rental', 'rental', 'Calendar', false],
            ['/rental', 'Rental Items and Settings', '/rental/manage', 'rental.manage', 'Grid', true],
            ['/rental', 'Bookings, Pickup and Return', '/rental/book', 'rental.book', 'Grid', true],
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
