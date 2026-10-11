<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for the Inventory & Warehouse closeout pages (in the group of the Warehouse page) and the two analytics reports
 * (next to the Stock report), granted to the global companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/warehouse', 'Stock Reservations', '/stockreservation', 'stockreservation', 'Lock', false],
            ['/stockreservation', 'Add Stock Reservation', '/stockreservation/add', 'stockreservation.add', 'Grid', true],
            ['/stockreservation', 'Release Stock Reservation', '/stockreservation/:id/edit', 'stockreservation.edit', 'Grid', true],
            ['sibling:/warehouse', 'Pick, Pack & Put-away', '/warehousetask', 'warehousetask', 'ClipboardList', false],
            ['/warehousetask', 'Add Warehouse List', '/warehousetask/add', 'warehousetask.add', 'Grid', true],
            ['/warehousetask', 'Work Warehouse List', '/warehousetask/:id/edit', 'warehousetask.edit', 'Grid', true],
            ['sibling:/warehouse', 'Damage, Expired & Wastage', '/stockwriteoff', 'stockwriteoff', 'Trash2', false],
            ['/stockwriteoff', 'Add Stock Write-off', '/stockwriteoff/add', 'stockwriteoff.add', 'Grid', true],
            ['sibling:/warehouse', 'Inventory Close', '/inventoryclose', 'inventoryclose', 'Lock', false],
            ['/inventoryclose', 'Close Inventory', '/inventoryclose/add', 'inventoryclose.add', 'Grid', true],
            ['/inventoryclose', 'Reopen Inventory', '/inventoryclose/reopen', 'inventoryclose.reopen', 'Grid', true],
            ['sibling:/report/stock', 'ABC Analysis', '/report/abc-analysis', 'report.abc-analysis', 'FileBarChart', false],
            ['sibling:/report/stock', 'Stock Ageing', '/report/stock-ageing', 'report.stock-ageing', 'FileBarChart', false],
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
