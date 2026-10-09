<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory, warehouse layer (W1): a warehouse can be given a capacity (in base units of the products, since the app
 * holds no product volume), and zones, racks, shelves and bins can be set up inside it. Both are additive: the capacity
 * is nullable and the locations are master data in their own table, holding no stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->decimal('capacity', 15, 3)->nullable()->after('fax');
        });

        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('warehouse_id')->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->enum('type', ['zone', 'rack', 'shelf', 'bin']);
            $table->string('code', 40);
            $table->string('name', 150);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('warehouse_locations')->onUpdate('cascade')->onDelete('restrict');
            $table->unique(['warehouse_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_locations');

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('capacity');
        });
    }
};
