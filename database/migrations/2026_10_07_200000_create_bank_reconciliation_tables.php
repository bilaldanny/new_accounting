<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts & Finance Phase 2: Bank Reconciliation (BRS). A statement is the bank's own statement for one bank
 * account (chart-of-accounts code) and period, entered from CSV rows. Each statement line (money in is positive,
 * money out negative) is matched to one posted ledger line of that account (`t_account_details.id`). Nothing
 * here posts to the ledger: a bank charge the books do not have yet is booked with an ordinary journal entry,
 * then matched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('account_code', 50);
            $table->date('statement_from');
            $table->date('statement_to');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2)->default(0);
            $table->enum('status', ['draft', 'reconciled'])->default('draft');
            $table->timestamp('reconciled_at')->nullable();
            $table->unsignedBigInteger('reconciled_by')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->index(['company_id', 'account_code']);
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_statement_id')->index();
            $table->date('txn_date');
            $table->string('description', 500)->nullable();
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('matched_detail_id')->nullable()->unique();
            $table->timestamp('matched_at')->nullable();
            $table->unsignedBigInteger('matched_by')->nullable();
            $table->timestamps();

            $table->foreign('bank_statement_id')->references('id')->on('bank_statements')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};
