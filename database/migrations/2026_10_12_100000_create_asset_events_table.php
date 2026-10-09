<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts & Finance Phase 3: a fixed asset's history. One row per acquisition, CWIP cost, capitalisation,
 * transfer, revaluation, impairment or disposal, with the ledger voucher it posted (`t_account_id`). Revaluation,
 * impairment and disposal start `pending` and post only when approved. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('fixed_asset_id')->index();
            $table->enum('type', ['acquisition', 'cwip_cost', 'capitalisation', 'transfer', 'revaluation', 'impairment', 'disposal']);
            $table->enum('status', ['posted', 'pending', 'approved', 'rejected'])->default('posted');
            $table->date('event_date');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('t_account_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('fixed_asset_id')->references('id')->on('fixed_assets')->onUpdate('cascade')->onDelete('cascade');
            $table->index(['fixed_asset_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_events');
    }
};
