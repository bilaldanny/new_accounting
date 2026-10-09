<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only. Reversing a completed collection used to delete its allocation rows outright,
     * losing the audit trail of what was collected against which invoice. Now the rows stay and are
     * timestamped instead, so a reversed collection's history can still be viewed.
     */
    public function up(): void
    {
        Schema::table('cash_collection_allocations', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_collection_allocations', function (Blueprint $table) {
            $table->dropColumn('reversed_at');
        });
    }
};
