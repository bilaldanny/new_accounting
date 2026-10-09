<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the prices of a sale or purchase already include its tax. Off for every existing document (their tax, if any, was
 * always added or typed on the header).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->boolean('tax_inclusive')->default(false)->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('tax_inclusive');
        });
    }
};
