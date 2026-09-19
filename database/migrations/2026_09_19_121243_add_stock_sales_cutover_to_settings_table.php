<?php

use App\Services\StockMovements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales now reduce stock, but only sales created at or after this moment (see StockMovements).
 * Running the migration on an existing installation stamps "now", so history is not
 * retroactively deducted on deploy. NULL (a fresh install has no settings row) means no cutoff.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'stock_sales_cutover_at')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->timestamp('stock_sales_cutover_at')->nullable();
        });

        DB::table('settings')->update(['stock_sales_cutover_at' => now()]);

        StockMovements::forgetSchemaMemo();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('settings', 'stock_sales_cutover_at')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('stock_sales_cutover_at');
        });

        StockMovements::forgetSchemaMemo();
    }
};
