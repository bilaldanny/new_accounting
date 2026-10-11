<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu rows for the Products & Catalog closeout pages, in the same group as the Product page, granted to the global
 * companyadmin role: Custom Attributes, Product Discount Rules, Warranty Claims, Consignment Stock, Kits & BOM and the
 * Opening Stock screen (its old hidden rows are brought back as a real page). Bulk update, export, scan lookup, barcodes
 * and tags use the Product page's own permissions.
 */
return new class extends Migration
{
    private const OWN_PATHS = [
        '/productattribute', '/productdiscountrule', '/warrantyclaim', '/consignment', '/productbom', '/openingstock',
    ];

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ...MenuMigration::crudPage('sibling:/product', 'Custom Attributes', '/productattribute', 'Sliders'),
            ...MenuMigration::crudPage('sibling:/product', 'Product Discount Rules', '/productdiscountrule', 'Percent'),
            ...MenuMigration::crudPage('sibling:/product', 'Warranty Claims', '/warrantyclaim', 'ShieldCheck'),
            ['sibling:/product', 'Consignment Stock', '/consignment', 'consignment', 'Package', false],
            ['/consignment', 'Add Consignment Settlement', '/consignment/add', 'consignment.add', 'Grid', true],
            ['/consignment', 'Settle Consignment', '/consignment/:id/edit', 'consignment.edit', 'Grid', true],
            ['/consignment', 'Delete Consignment Settlement', '/consignment/delete', 'consignment.delete', 'Grid', true],
            ['sibling:/product', 'Kits & BOM', '/productbom', 'productbom', 'Layers', false],
            ['/productbom', 'Edit Components', '/productbom/:id/edit', 'productbom.edit', 'Grid', true],
            ['/productbom', 'Kitting and De-kitting', '/productbom/kitting', 'productbom.kitting', 'Grid', true],
            ['sibling:/product', 'Opening Stock', '/openingstock', 'openingstock', 'Archive', false],
            ['/openingstock', 'Add Opening Stock', '/openingstock/add', 'openingstock.add', 'Grid', true],
        ];
    }

    public function up(): void
    {
        MenuMigration::install($this->rows());

        // The legacy hidden Opening Stock rows (left over from the old app) become a real, permissioned page.
        // (2026_09_19_060918 soft deleted them as dead rows; the page exists now, so they come back.)
        $restored = Schema::hasColumn('menus', 'deleted_at') ? ['deleted_at' => null] : [];

        DB::table('menus')->where('route_path', '/openingstock')->update(['is_hidden' => 0, 'is_permission' => 1, 'is_active' => 1, 'name' => 'Opening Stock'] + $restored);
        DB::table('menus')->where('route_path', '/openingstock/add')->update(['is_permission' => 1, 'is_active' => 1] + $restored);
    }

    public function down(): void
    {
        MenuMigration::remove(array_column(array_filter($this->rows(), fn (array $row): bool => $row[2] !== '/openingstock' && $row[2] !== '/openingstock/add'), 2));
    }
};
