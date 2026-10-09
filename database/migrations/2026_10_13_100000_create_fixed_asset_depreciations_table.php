<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts & Finance Phase 3: the depreciation engine's book. One row per asset per month depreciated, with the
 * amount, the book value either side of it and the ledger voucher it was posted in. Unique on asset + month, which
 * is what makes running the engine again harmless. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('fixed_asset_id');
            $table->char('period', 7);
            $table->date('period_end');
            $table->decimal('amount', 15, 2);
            $table->decimal('opening_book_value', 15, 2);
            $table->decimal('closing_book_value', 15, 2);
            $table->unsignedBigInteger('t_account_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('fixed_asset_id')->references('id')->on('fixed_assets')->onUpdate('cascade')->onDelete('cascade');
            $table->unique(['fixed_asset_id', 'period']);
            $table->index(['company_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
    }
};
