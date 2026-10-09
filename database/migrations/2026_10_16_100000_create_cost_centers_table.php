<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts & Finance Phase 3: cost centers and profit centers. A center belongs to a company, may sit under another
 * (`parent_id`), and may belong to a branch and a department. Ledger lines (`t_account_details.cost_center_id`) and fixed
 * assets (`fixed_assets.cost_center_id`) can point at one. Additive only: the two new columns are nullable and
 * nothing already stored changes, so every existing voucher stays "unallocated".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->enum('type', ['cost', 'profit'])->default('cost');
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('cost_centers')->onUpdate('cascade')->onDelete('restrict');
            $table->unique(['company_id', 'code']);
        });

        Schema::table('t_account_details', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_center_id')->nullable()->index();
            $table->foreign('cost_center_id')->references('id')->on('cost_centers')->onUpdate('cascade')->onDelete('set null');
        });

        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_center_id')->nullable()->after('branch_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn('cost_center_id');
        });

        Schema::table('t_account_details', function (Blueprint $table) {
            $table->dropForeign(['cost_center_id']);
            $table->dropColumn('cost_center_id');
        });

        Schema::dropIfExists('cost_centers');
    }
};
