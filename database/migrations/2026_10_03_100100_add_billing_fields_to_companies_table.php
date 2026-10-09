<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant billing state, directly on `companies` — the same place `max_users`/`max_branches` already
 * live, rather than a separate "tenant" table (a company already IS the tenant in this app's
 * multi-tenant model). `tenant_status` starts every new company on 'trial' so existing rows (and any
 * company created before this module existed) read as actively-trialing rather than silently
 * unsubscribed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('subscription_plan_id')->nullable()->after('max_branches')
                ->constrained('subscription_plans')->nullOnDelete();
            $table->string('tenant_status')->default('trial')->after('subscription_plan_id');
            $table->timestamp('trial_ends_at')->nullable()->after('tenant_status');
            $table->timestamp('current_period_starts_at')->nullable()->after('trial_ends_at');
            $table->timestamp('current_period_ends_at')->nullable()->after('current_period_starts_at');
            $table->unsignedInteger('max_warehouses')->default(2)->after('current_period_ends_at');
            $table->unsignedInteger('max_products')->default(500)->after('max_warehouses');
            $table->unsignedInteger('max_invoices_per_month')->default(200)->after('max_products');
            $table->unsignedInteger('max_storage_mb')->default(500)->after('max_invoices_per_month');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_plan_id');
            $table->dropColumn([
                'tenant_status',
                'trial_ends_at',
                'current_period_starts_at',
                'current_period_ends_at',
                'max_warehouses',
                'max_products',
                'max_invoices_per_month',
                'max_storage_mb',
            ]);
        });
    }
};
