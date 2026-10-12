<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Wave 1: the Security Center (settings, IP rules, sessions, privacy), Lead Allocation and Subscription Billing,
 * granted to the global companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/user', 'Security Center', '/security', 'security', 'ShieldCheck', false],
            ['/security', 'Manage Security Settings', '/security/manage', 'security.manage', 'Grid', true],
            ['/security', 'Personal Data Requests', '/security/privacy', 'security.privacy', 'Grid', true],
            ['sibling:/leads', 'Lead Allocation', '/leadallocation', 'leadallocation', 'Shuffle', false],
            ['sibling:/customersubscriptions', 'Subscription Billing', '/subscriptionbilling', 'subscriptionbilling', 'RefreshCw', false],
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
