<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A billing-cycle invoice for a customer subscription — the same manual-payment shape as Module 17's
 * `subscription_invoices` (method/reference/paid_amount/document columns, not forced through the
 * sell/purchase-only `payments` table), copied rather than shared: this one bills a `Contact` (a
 * tenant's customer), that one bills a `Company` (a platform tenant) — different billed parties, and
 * this codebase's own convention is a second small, obvious implementation over one generalized/
 * polymorphic one (confirmed zero `morphTo`/`morphMany` usage anywhere in the app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_subscription_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_no')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 12, 2);
            $table->decimal('setup_fee_amount', 12, 2)->default(0);
            $table->decimal('metered_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->string('status')->default('unpaid');
            $table->date('due_date');
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('document')->nullable();
            $table->decimal('refunded_amount', 12, 2)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('marked_paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_subscription_invoices');
    }
};
