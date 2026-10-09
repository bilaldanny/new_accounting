<?php

use App\Services\StockMovements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory, warehouse layer (W1): a stock document may name the warehouse (inside its branch) it moved stock in or
 * out of, and a transfer may name the warehouse it arrives in. Both are nullable and additive: nothing already stored
 * changes, a document with no warehouse is "unassigned" and every branch-level stock figure stays exactly as it was.
 * Stock is still derived from the documents (Services\StockMovements); there is no stored stock to migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('tobranch_id')->index();
            $table->unsignedBigInteger('towarehouse_id')->nullable()->after('warehouse_id')->index();

            $table->foreign('warehouse_id')->references('id')->on('warehouses')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('towarehouse_id')->references('id')->on('warehouses')->onUpdate('cascade')->onDelete('restrict');
        });

        StockMovements::forgetSchemaMemo();
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropForeign(['towarehouse_id']);
            $table->dropColumn(['warehouse_id', 'towarehouse_id']);
        });

        StockMovements::forgetSchemaMemo();
    }
};
