<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `transactions.type` was created as an enum that lists 'salereturn', while the application has always saved a sell return as
 * 'sellreturn' (Transaction::TYPE_SELL_RETURN). SQLite ignores enums, which is how this went unseen in the tests, but MySQL in strict
 * mode refuses the row ("Data truncated for column 'type'"), so no sell return could be saved there. 'sellreturn' is added to the list;
 * 'salereturn' stays, so any row that has it is still valid. Nothing else changes and no row is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('transactions')) {
            return;
        }

        $column = DB::selectOne("show columns from `transactions` where Field = 'type'");

        if ($column === null || str_contains((string) $column->Type, "'sellreturn'")) {
            return;
        }

        DB::statement("alter table `transactions` modify `type` enum('opening_stock','purchaseorder','sell','issue_note','adjustment','transfer','purchasereturn','recieving_note','salereturn','sellreturn') null");
    }

    public function down(): void
    {
        // not reversed: a down() would have to delete or rewrite every sell return, which is not a risk worth taking
    }
};
