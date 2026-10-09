<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SaaS plan catalog itself (Basic/Standard/Premium, etc.) — global, superadmin-owned master data,
 * not company-scoped like most other masters in this app (a tenant subscribes TO a plan, it does not
 * own one). Each plan carries the limits a company on it gets, mirroring the fields `companies` already
 * has (`max_users`, `max_branches`) plus the new ones this module adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('billing_cycle')->default('monthly');
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('trial_days')->default(14);
            $table->unsignedInteger('max_users')->default(10);
            $table->unsignedInteger('max_branches')->default(2);
            $table->unsignedInteger('max_warehouses')->default(2);
            $table->unsignedInteger('max_products')->default(500);
            $table->unsignedInteger('max_invoices_per_month')->default(200);
            $table->unsignedInteger('max_storage_mb')->default(500);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
