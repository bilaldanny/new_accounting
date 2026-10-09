<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a real lookup FK alongside the free-text `source` column Step 1 shipped with. `source` is kept
 * (existing rows keep their free-text value, and it's still accepted on write as a fallback when no
 * `lead_source_id` is given), so this is additive, not a breaking rename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_source_id')->nullable()->after('source')->index();

            $table->foreign('lead_source_id')->references('id')->on('lead_sources')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['lead_source_id']);
            $table->dropColumn('lead_source_id');
        });
    }
};
