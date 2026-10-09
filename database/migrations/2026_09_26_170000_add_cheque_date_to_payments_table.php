<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Post-dated cheque (PDC) tracking: an optional date on a `cheque` method payment/receipt for when
     * the cheque is meant to clear, distinct from `paid_on` (when it was received/handed over). Additive
     * only; existing cheque payments simply have no date until edited.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->date('cheque_date')->nullable()->after('cheque_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('cheque_date');
        });
    }
};
