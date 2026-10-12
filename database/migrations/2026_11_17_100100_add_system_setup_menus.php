<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Wave 6: Business Settings (number format, POS, notifications, branch tree, import / export center, import history,
 * data migration, exchange rate sync, retention) with its manage permission, the Setup Wizard, the Help Center, the platform
 * System Center and the Franchise Overview, granted to the global companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/user', 'Business Settings', '/businesssettings', 'businesssettings', 'SlidersHorizontal', false],
            ['/businesssettings', 'Manage Business Settings', '/businesssettings/manage', 'businesssettings.manage', 'Grid', true],
            ['/businesssettings', 'Import and Migrate Data', '/businesssettings/import', 'businesssettings.import', 'Grid', true],
            ['sibling:/user', 'Setup Wizard', '/setup', 'setup', 'ListChecks', false],
            ['sibling:/user', 'Help Center', '/help', 'help', 'LifeBuoy', false],
            ['sibling:/user', 'System Center', '/systemcenter', 'systemcenter', 'Server', false],
            ['sibling:/user', 'Franchise Overview', '/franchise', 'franchise', 'Network', false],
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
