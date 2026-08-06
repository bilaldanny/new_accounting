<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->nullable()->unsigned()->index();
            $table->bigInteger('unit_id')->nullable()->unsigned()->index();
            $table->bigInteger('brand_id')->nullable()->unsigned()->index();
            $table->bigInteger('category_id')->nullable()->unsigned()->index();
            $table->bigInteger('subcategory_id')->nullable()->unsigned()->index();
            $table->bigInteger('itemtype_id')->nullable()->unsigned()->index();
            $table->bigInteger('warranty_id')->nullable()->unsigned()->index();
            $table->string('name');
            $table->Integer('alert_qty')->nullable();
            $table->string('sku');
            $table->Integer('weight')->nullable();
            $table->text('product_desc')->nullable();
            $table->text('product_image')->nullable();
            $table->boolean('active')->default(1);
            $table->enum('type', ['single', 'variable'])->default('single');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('unit_id')->references('id')->on('units')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('brand_id')->references('id')->on('brands')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('subcategory_id')->references('id')->on('categories')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('itemtype_id')->references('id')->on('item_types')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('warranty_id')->references('id')->on('warranties')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('products');
    }
}
