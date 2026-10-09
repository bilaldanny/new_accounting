<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's contract against one of the tenant's own plans — the "subscription contract" the master
 * list asks for. `auto_renew` drives whether the next cycle's invoice gets generated automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('trial');
            $table->date('start_date');
            $table->timestamp('trial_ends_at')->nullable();
            $table->date('current_period_starts_at');
            $table->date('current_period_ends_at');
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_subscriptions');
    }
};
