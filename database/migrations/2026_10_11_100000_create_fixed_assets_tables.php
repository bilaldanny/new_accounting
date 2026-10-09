<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts & Finance Phase 3: the Fixed Asset Register. A category carries the depreciation defaults and the
 * chart-of-accounts accounts its assets post to (the asset itself, accumulated depreciation, depreciation expense,
 * and optionally CWIP, revaluation surplus, impairment loss and gain/loss on disposal). An asset is one register
 * line. `accumulated_depreciation` is the running total (an opening figure for an asset carried over from before
 * the register, then whatever the depreciation engine adds). Additive only: nothing here touches existing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('name', 150);
            $table->enum('method', ['straight_line', 'declining_balance', 'wdv'])->default('straight_line');
            $table->unsignedInteger('useful_life_months')->nullable();
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('salvage_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('asset_coa_id');
            $table->unsignedBigInteger('accumulated_coa_id');
            $table->unsignedBigInteger('expense_coa_id');
            $table->unsignedBigInteger('cwip_coa_id')->nullable();
            $table->unsignedBigInteger('revaluation_coa_id')->nullable();
            $table->unsignedBigInteger('impairment_coa_id')->nullable();
            $table->unsignedBigInteger('disposal_coa_id')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->unique(['company_id', 'name']);
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('asset_category_id')->index();
            $table->string('code', 40);
            $table->string('name', 200);
            $table->string('description', 500)->nullable();
            $table->string('serial_no', 100)->nullable();
            $table->enum('status', ['cwip', 'active', 'disposed'])->default('active');
            $table->enum('source', ['opening', 'purchase', 'cwip'])->default('opening');
            $table->date('acquired_on');
            $table->date('in_service_on')->nullable();
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('salvage_value', 15, 2)->default(0);
            $table->decimal('accumulated_depreciation', 15, 2)->default(0);
            $table->enum('method', ['straight_line', 'declining_balance', 'wdv'])->default('straight_line');
            $table->unsignedInteger('useful_life_months')->nullable();
            $table->decimal('rate', 8, 4)->nullable();
            $table->date('depreciated_through')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('asset_category_id')->references('id')->on('asset_categories')->onUpdate('cascade')->onDelete('restrict');
            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('asset_categories');
    }
};
