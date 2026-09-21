<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A refund owed to a customer for a gift card payment whose card no longer exists (it was deleted for
     * good, which takes its ledger with it), so the money cannot be credited back to a balance. One signed
     * row per movement (positive: owed, negative: a restored sale took it back), kept for someone to settle
     * by hand; `resolved_at` closes a row once that has been done.
     */
    public function up(): void
    {
        Schema::create('gift_card_orphan_refunds', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->nullable()->unsigned()->index();
            $table->bigInteger('transaction_id')->nullable()->unsigned()->index();
            $table->string('gift_card_code', 50);
            $table->double('amount', 9, 2);
            $table->string('reason', 255);
            $table->bigInteger('created_by')->nullable()->unsigned();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_note', 500)->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onUpdate('cascade')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gift_card_orphan_refunds');
    }
};
