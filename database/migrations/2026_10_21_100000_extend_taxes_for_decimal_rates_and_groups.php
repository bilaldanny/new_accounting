<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax rates become decimals (0.5, 17.5 ...; an integer fits a decimal(8,4) exactly, so no stored rate changes) and a tax
 * group can stack its taxes (`compound`: each tax after the first is charged on the amount plus the taxes before it; the
 * order is the order of `sub_tax`). `kind` separates the taxes put on a document line (`sales`) from withholding taxes, and
 * `applies_on` says whether a withholding rate is taken on the net value or the gross one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table): void {
            $table->decimal('percentage', 8, 4)->change();
        });

        Schema::table('taxes', function (Blueprint $table): void {
            $table->boolean('compound')->default(false)->after('type');
            $table->string('kind', 20)->default('sales')->after('compound');
            $table->string('applies_on', 10)->default('net')->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table): void {
            $table->dropColumn(['compound', 'kind', 'applies_on']);
        });

        Schema::table('taxes', function (Blueprint $table): void {
            $table->integer('percentage')->change();
        });
    }
};
