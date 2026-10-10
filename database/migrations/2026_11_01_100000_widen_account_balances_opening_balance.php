<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `account_balances.opening_balance` was an INTEGER column, so every opening balance with paisa was cut to a whole number when it
 * was stored (and a year rollover would have cut every closing balance the same way). It becomes decimal(15,2), the precision of
 * every other money column. Widening keeps every value: whole numbers become `n.00`, nothing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_balances', function (Blueprint $table) {
            $table->decimal('opening_balance', 15, 2)->default(0)->change();
        });
    }

    /**
     * Rolling back narrows the column to a whole number again: stored decimals are CUT (rounded to the nearest whole unit by the
     * database) and cannot be recovered. Only roll back before any decimal opening balance or year rollover has been saved.
     */
    public function down(): void
    {
        Schema::table('account_balances', function (Blueprint $table) {
            $table->integer('opening_balance')->change();
        });
    }
};
