<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('price_list_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('list_id')->unsigned()->index();
            $table->bigInteger('product_id')->unsigned()->index();
            $table->bigInteger('variation_id')->nullable()->unsigned()->index();
            $table->bigInteger('unit_id')->nullable()->unsigned()->index();
            $table->double('purchase_price', 9, 2)->default(0);
            $table->double('sell_price', 9, 2)->default(0);
            $table->double('profit_margin', 9, 2)->default(0);
            $table->double('discount', 9, 2)->default(0);
            $table->timestamps();

            $table->foreign('list_id')->references('id')->on('price_lists')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_list_details');
    }
};
