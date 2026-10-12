<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refund of an unused advance, for both sides: a customer advance kept by a cash collection (money paid back to the customer)
 * and a supplier advance kept by a supplier payment batch (money the supplier pays back). One row per refund; the refund's own
 * voucher is the payment `payment_id` points at. The advance's remaining balance is its amount less what was used on documents
 * less what was refunded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('advance_refunds')) {
            return;
        }

        Schema::create('advance_refunds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('party', 10);
            $table->unsignedBigInteger('contact_id')->index();
            $table->unsignedBigInteger('cash_collection_id')->nullable()->index();
            $table->unsignedBigInteger('supplier_payment_batch_id')->nullable()->index();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('refunded_on');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_refunds');
    }
};
