<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A cash collection is money a collector took from a customer. It starts `pending` (a promise, no
     * accounting), and completing it spreads the amount over the customer's open invoices as ordinary sell
     * payments (`cash_collection_allocations.payment_id` points at each one). `cancelled` never had any
     * effect. Nothing is posted until it completes.
     */
    public function up(): void
    {
        Schema::create('cash_collections', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->unsigned()->index();
            $table->bigInteger('branch_id')->unsigned()->index();
            $table->bigInteger('contact_id')->unsigned()->index();
            $table->string('reference', 50);
            $table->date('collected_on');
            $table->double('amount', 9, 2);
            $table->string('note', 500)->nullable();
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            $table->bigInteger('payment_account')->nullable()->unsigned();
            $table->bigInteger('collected_by')->nullable()->unsigned();
            $table->bigInteger('completed_by')->nullable()->unsigned();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onUpdate('cascade')->onDelete('cascade');
        });

        Schema::create('cash_collection_allocations', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('cash_collection_id')->unsigned()->index();
            $table->bigInteger('transaction_id')->unsigned()->index();
            $table->bigInteger('payment_id')->nullable()->unsigned()->index();
            $table->double('amount', 9, 2);
            $table->timestamps();

            $table->foreign('cash_collection_id')->references('id')->on('cash_collections')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_collection_allocations');
        Schema::dropIfExists('cash_collections');
    }
};
