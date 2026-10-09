<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant's subscription invoice, billed against a plan's cycle. Payment is manual-only (per this
 * build's "manual-payment model" scope — no gateway yet): rather than forcing this through the existing
 * `payments` table (built around a sell/purchase `Transaction` + a `Contact` ledger posting, neither of
 * which a subscription invoice has), the same manual-entry shape `Payment` already uses (method, a
 * reference, a paid-on date, an optional attachment) is kept directly on this table. Nothing here blocks
 * wiring a real gateway later: `status`/`paid_at`/`paid_amount` would simply start being set by a
 * webhook handler instead of `markPaid()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->string('invoice_no')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->string('status')->default('unpaid');
            $table->date('due_date');
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('document')->nullable();
            $table->foreignId('marked_paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
