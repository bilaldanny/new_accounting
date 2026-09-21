<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only. A completed collection may keep part of the cash as a customer advance
     * (`advance_amount`, posted as one payment with no invoice: `advance_payment_id`) that later
     * settles invoices; those uses are allocation rows of kind `advance` (the original allocations are kind
     * `collected`). A reversed collection goes back to pending, remembering when and by whom.
     */
    public function up(): void
    {
        Schema::table('cash_collections', function (Blueprint $table) {
            $table->double('advance_amount', 9, 2)->default(0)->after('amount');
            $table->bigInteger('advance_payment_id')->nullable()->unsigned()->after('payment_account');
            $table->timestamp('reversed_at')->nullable()->after('cancelled_at');
            $table->bigInteger('reversed_by')->nullable()->unsigned()->after('reversed_at');
        });

        Schema::table('cash_collection_allocations', function (Blueprint $table) {
            $table->string('kind', 20)->default('collected')->after('payment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_collection_allocations', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::table('cash_collections', function (Blueprint $table) {
            $table->dropColumn(['advance_amount', 'advance_payment_id', 'reversed_at', 'reversed_by']);
        });
    }
};
