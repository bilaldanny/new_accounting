<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription Analytics read their numbers from a snapshot (one row per company and kind, refreshed by
 * `customersubscriptions:refresh-analytics` and whenever a subscription, invoice or plan changes), and the queries that still
 * run get the composite indexes they filter on. Creates a table and adds indexes only: no existing data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_subscription_analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 40);
            $table->string('kind', 40);
            $table->json('payload');
            $table->timestamp('generated_at');
            $table->unique(['scope', 'kind'], 'csas_scope_kind_unique');
        });

        Schema::table('customer_subscriptions', function (Blueprint $table) {
            $table->index(['company_id', 'status', 'current_period_ends_at'], 'cs_company_status_period_idx');
            $table->index(['company_id', 'status', 'cancelled_at'], 'cs_company_status_cancelled_idx');
        });

        Schema::table('customer_subscription_invoices', function (Blueprint $table) {
            $table->index(['company_id', 'status', 'paid_at'], 'csi_company_status_paid_idx');
            $table->index(['company_id', 'status', 'customer_subscription_id'], 'csi_company_status_sub_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_subscription_invoices', function (Blueprint $table) {
            $table->dropIndex('csi_company_status_sub_idx');
            $table->dropIndex('csi_company_status_paid_idx');
        });

        Schema::table('customer_subscriptions', function (Blueprint $table) {
            $table->dropIndex('cs_company_status_cancelled_idx');
            $table->dropIndex('cs_company_status_period_idx');
        });

        Schema::dropIfExists('customer_subscription_analytics_snapshots');
    }
};
