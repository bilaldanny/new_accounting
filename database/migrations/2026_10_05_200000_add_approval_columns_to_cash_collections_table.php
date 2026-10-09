<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval Center (Phase 1, easy half): a supervisor can review and stamp a pending cash collection
 * as reviewed before the cashier calls `complete()`. This is purely an additive audit stamp — it does
 * NOT gate `complete()` (which keeps working on `pending` exactly as before), so existing collection
 * tests and flows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_collections', function (Blueprint $table) {
            $table->bigInteger('approved_by')->nullable()->unsigned()->index()->after('collected_by');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('cash_collections', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'approved_at']);
        });
    }
};
