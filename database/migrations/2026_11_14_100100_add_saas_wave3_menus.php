<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Wave 3: the tenant's own Subscription & Billing page (and its manage permission), Integrations (couriers, payment
 * gateway, bank feed), the platform SaaS Admin page, and Support Tickets, granted to the global companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/tenants', 'SaaS Admin', '/saasadmin', 'saasadmin', 'Server', false],
            ['sibling:/user', 'Subscription & Billing', '/tenant-billing', 'tenant-billing', 'CreditCard', false],
            ['/tenant-billing', 'Manage Subscription', '/tenant-billing/manage', 'tenant-billing.manage', 'Grid', true],
            ['sibling:/user', 'Integrations', '/integrations', 'integrations', 'Plug', false],
            ['/integrations', 'Manage Integrations', '/integrations/manage', 'integrations.manage', 'Grid', true],
            ['sibling:/user', 'Support Tickets', '/support', 'support', 'LifeBuoy', false],
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
