<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The loyalty points a customer redeemed on a sale at checkout (at most one row per sale): who, how many
     * points were asked for and the currency value they took off `final_amount` when the sale was saved. The
     * points ledger (`loyalty_point_entries`) holds what is actually spent right now; this row is the
     * baseline that sale is meant to spend, so a sale that is deleted or drafted gives the points back and
     * restoring it spends them again, and a return gives back its share.
     */
    public function up(): void
    {
        Schema::create('sale_loyalty_redemptions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('transaction_id')->unsigned()->unique();
            $table->bigInteger('contact_id')->unsigned()->index();
            $table->unsignedInteger('points');
            $table->double('amount', 9, 2);
            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_loyalty_redemptions');
    }
};
