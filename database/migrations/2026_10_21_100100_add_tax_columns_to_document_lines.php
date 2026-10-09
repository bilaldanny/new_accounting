<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sale and purchase lines can carry their own tax: the tax picked for the line and the amount it came to. Both are
 * nullable and nothing is back-filled, so every existing document stays exactly as it is (its tax, if any, is still the
 * one amount on the document header).
 */
return new class extends Migration
{
    private const TABLES = ['sell_lines', 'purchase_lines'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('tax_id')->nullable()->index();
                $table->decimal('tax_amount', 15, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_tax_id_index');
                $table->dropColumn(['tax_id', 'tax_amount']);
            });
        }
    }
};
