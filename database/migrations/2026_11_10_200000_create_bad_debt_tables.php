<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bad debt (final closeout, task C): both methods, per request of the owner.
 *
 *  - bad_debt_settings: the bad debt expense account, the allowance for doubtful debts account and the rate per aging bucket.
 *  - bad_debt_writeoffs: a direct write-off of an invoice (or part of it). It is requested, then approved by someone else, and
 *    only then posted: Dr the expense account (or the allowance) / Cr the customer, counted as a payment on the invoice so its
 *    balance, the customer ledger and the aging all follow. It can be reversed.
 *  - allowance_provisions: the period-end allowance, one per day: the allowance is set to the rate of each aging bucket and the
 *    change from the balance already held is posted as a journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bad_debt_settings')) {
            Schema::create('bad_debt_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->unsignedBigInteger('expense_account_id')->nullable();
                $table->unsignedBigInteger('allowance_account_id')->nullable();
                $table->json('rates')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bad_debt_writeoffs')) {
            Schema::create('bad_debt_writeoffs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->unsignedBigInteger('contact_id')->index();
                $table->decimal('amount', 15, 2);
                $table->date('written_off_on');
                $table->string('method', 10)->default('direct');
                $table->string('reason', 255);
                $table->string('status', 10)->default('pending')->index();
                $table->unsignedBigInteger('payment_id')->nullable();
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('allowance_provisions')) {
            Schema::create('allowance_provisions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->date('as_of');
                $table->json('buckets');
                $table->decimal('required', 15, 2);
                $table->decimal('existing', 15, 2);
                $table->decimal('change', 15, 2);
                $table->unsignedBigInteger('t_account_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'as_of']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_provisions');
        Schema::dropIfExists('bad_debt_writeoffs');
        Schema::dropIfExists('bad_debt_settings');
    }
};
