<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant's OWN subscription product catalog — what THEY sell to THEIR customers (gym membership,
 * maintenance contract, their own SaaS, ...). Deliberately a separate table from `subscription_plans`
 * (Module 17's platform-owner plan catalog, global and superadmin-only): the two are unrelated concepts
 * that only happen to share the word "plan" — this one is company-scoped, one row per tenant's product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('billing_cycle')->default('monthly');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('setup_fee', 12, 2)->default(0);
            $table->unsignedInteger('trial_days')->default(0);
            $table->boolean('is_metered')->default(false);
            $table->string('unit_label')->nullable();
            $table->unsignedInteger('included_units')->nullable();
            $table->decimal('overage_rate', 12, 4)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_subscription_plans');
    }
};
