<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('product_id')->nullable()->unsigned()->index();
            $table->string('name');
            $table->string('sku');
            $table->string('variation_name');
            $table->double('default_purchase_price', 9, 2);
            $table->double('dpp_unit_price', 9, 2);
            $table->Integer('largequantity');
            $table->Integer('smallquantity');
            $table->double('profit_percent', 9, 2);
            $table->double('default_sell_price', 9, 2);
            $table->text('variation_image')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('product_details');
    }
}
