<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deleted sell or purchase return sets the returned quantity of its document's lines back to zero (so stock and reports stop
 * counting it). The quantities are kept here, on the return, so that restoring the return can put them back. Additive and
 * nullable: returns deleted before this column existed simply have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'returned_lines')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->json('returned_lines')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('returned_lines');
        });
    }
};
