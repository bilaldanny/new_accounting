<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The discount code applied to a sale (at most one per sale): which discount, the code, name, type and
     * value as they were at that moment, and the amount it took off. `discount_id` survives the discount
     * being deleted (set null) and the snapshot columns keep the record readable for reports and audit.
     */
    public function up(): void
    {
        Schema::create('sale_discounts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('transaction_id')->unsigned()->unique();
            $table->bigInteger('discount_id')->nullable()->unsigned()->index();
            $table->string('code', 50);
            $table->string('name');
            $table->enum('discount_type', ['percentage', 'fixed']);
            $table->double('value', 9, 2);
            $table->double('amount', 9, 2);
            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('discount_id')->references('id')->on('discounts')->onUpdate('cascade')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_discounts');
    }
};
