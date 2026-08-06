<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePurchaseSellLinesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('purchase_sell_lines', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('purchaseline_id')->nullable()->unsigned()->index();
            $table->bigInteger('sellline_id')->nullable()->unsigned()->index();
            $table->double('quantity', 9, 2)->default(0);
            $table->double('quantity_returned', 9, 2)->default(0);
            $table->timestamps();

            $table->foreign('sellline_id')->references('id')->on('sell_lines')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('purchaseline_id')->references('id')->on('purchase_lines')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('purchase_sell_lines');
    }
}
