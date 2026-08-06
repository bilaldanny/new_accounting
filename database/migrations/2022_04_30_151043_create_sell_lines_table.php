<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSellLinesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sell_lines', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('transaction_id')->nullable()->unsigned()->index();
            $table->bigInteger('product_id')->nullable()->unsigned()->index();
            $table->bigInteger('variation_id')->nullable()->unsigned()->index();
            $table->bigInteger('itemtype_id')->nullable()->unsigned()->index();
            $table->bigInteger('unit_id')->nullable()->unsigned()->index();
            $table->double('quantity', 9, 2)->default(0);
            $table->double('quantity_issue', 9, 2)->default(0);
            $table->double('quantity_returned', 9, 2)->default(0);
            $table->double('unit_price', 9, 2)->default(0);
            $table->double('discount_percent', 9, 2)->default(0);
            $table->double('unit_price_after_discount', 9, 2)->default(0);
            $table->double('subtotal', 9, 2)->default(0);
            $table->Integer('packing_qty')->default(0);
            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('product_details')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('itemtype_id')->references('id')->on('item_types')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('unit_id')->references('id')->on('units')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sell_lines');
    }
}
