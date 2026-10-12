<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inter-branch transactions inside one company (final closeout, task C). Companies stay separate tenants: nothing here writes
 * across companies.
 *
 *  - interbranch_settings: the two clearing accounts, "due from branches" and "due to branches".
 *  - interbranch_transactions: one transfer between two branches of a company and the two vouchers it made: the giving branch
 *    debits due-from and credits its own account, the receiving branch debits its account and credits due-to. The consolidated
 *    report eliminates the two clearing accounts against each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('interbranch_settings')) {
            Schema::create('interbranch_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->unsignedBigInteger('receivable_account_id')->nullable();
                $table->unsignedBigInteger('payable_account_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('interbranch_transactions')) {
            Schema::create('interbranch_transactions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('from_branch_id')->index();
                $table->unsignedBigInteger('to_branch_id')->index();
                $table->decimal('amount', 15, 2);
                $table->date('transaction_date');
                $table->string('reference', 60)->nullable();
                $table->string('description', 255)->nullable();
                $table->unsignedBigInteger('from_account_id');
                $table->unsignedBigInteger('to_account_id');
                $table->unsignedBigInteger('from_voucher_id')->nullable();
                $table->unsignedBigInteger('to_voucher_id')->nullable();
                $table->string('status', 10)->default('posted');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('interbranch_transactions');
        Schema::dropIfExists('interbranch_settings');
    }
};
