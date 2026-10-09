<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products & Catalog Phase 2, pricing cluster: quantity-break tiers on price list lines (`price_list_tiers`: from
 * `min_qty` units the line sells at `sell_price`), and a minimum / maximum selling price per variation
 * (`product_details.min_sell_price` / `max_sell_price`, per selling unit, nullable = no limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('price_list_detail_id')->index();
            $table->decimal('min_qty', 12, 2);
            $table->decimal('sell_price', 15, 2);
            $table->timestamps();

            $table->foreign('price_list_detail_id')->references('id')->on('price_list_details')->onUpdate('cascade')->onDelete('cascade');
        });

        Schema::table('product_details', function (Blueprint $table) {
            $table->decimal('min_sell_price', 15, 2)->nullable();
            $table->decimal('max_sell_price', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_details', function (Blueprint $table) {
            $table->dropColumn(['min_sell_price', 'max_sell_price']);
        });

        Schema::dropIfExists('price_list_tiers');
    }
};
