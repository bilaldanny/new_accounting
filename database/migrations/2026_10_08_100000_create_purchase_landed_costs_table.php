<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement Phase 2: Landed Cost Calculator. The extra costs of getting a purchase to the warehouse
 * (freight, customs duty, insurance, clearing) are kept per purchase and spread over its lines;
 * `purchase_lines.landed_cost` is the share of the line (its whole quantity, not per unit). The average cost
 * (Reports\AverageCost, the one cost definition stock valuation and item profit use) includes it. Nothing here
 * posts to the ledger: the bill for the freight is booked with an ordinary expense or payment voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_landed_costs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id')->index();
            $table->string('type', 30);
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 15, 2);
            $table->enum('allocation_basis', ['value', 'quantity'])->default('value');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions')->onUpdate('cascade')->onDelete('cascade');
        });

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->double('landed_cost', 12, 2)->default(0)->after('pp_without_discount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropColumn('landed_cost');
        });

        Schema::dropIfExists('purchase_landed_costs');
    }
};
