<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts & Finance closeout, part A (wave 5). Additive and nullable: a company that uses none of it sees what it saw before.
 *
 * Cash: petty_cash_funds + petty_cash_entries (imprest), cheque_layouts (cheque printing), bank_statement_lines.charge_t_account_id
 * (bank charge made from a statement line). Registers: bank_instruments (guarantees and letters of credit), loans +
 * loan_schedule_rows, tax_payments (challans). Terms: payment_terms + contacts.payment_term_id + early_payment_discounts.
 * Year end: year_closings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->cash();
        $this->registers();
        $this->terms();
        $this->yearEnd();
    }

    private function cash(): void
    {
        if (! Schema::hasTable('petty_cash_funds')) {
            Schema::create('petty_cash_funds', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('name', 120);
                $table->decimal('imprest_amount', 15, 2)->default(0);
                $table->unsignedBigInteger('cash_account_id');
                $table->unsignedBigInteger('custodian_user_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('petty_cash_entries')) {
            Schema::create('petty_cash_entries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('fund_id')->index();
                $table->string('entry_type', 10);
                $table->date('entry_date');
                $table->string('payee', 150)->nullable();
                $table->string('description', 255)->nullable();
                $table->string('receipt_no', 60)->nullable();
                $table->decimal('amount', 15, 2);
                $table->unsignedBigInteger('counter_account_id');
                $table->unsignedBigInteger('t_account_id')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cheque_layouts')) {
            Schema::create('cheque_layouts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 80);
                $table->decimal('width_mm', 6, 1)->default(203);
                $table->decimal('height_mm', 6, 1)->default(95);
                $table->decimal('payee_x', 6, 1)->default(25);
                $table->decimal('payee_y', 6, 1)->default(30);
                $table->decimal('words_x', 6, 1)->default(25);
                $table->decimal('words_y', 6, 1)->default(42);
                $table->decimal('amount_x', 6, 1)->default(150);
                $table->decimal('amount_y', 6, 1)->default(52);
                $table->decimal('date_x', 6, 1)->default(150);
                $table->decimal('date_y', 6, 1)->default(14);
                $table->unsignedSmallInteger('font_size')->default(12);
                $table->boolean('account_payee')->default(true);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('bank_statement_lines') && ! Schema::hasColumn('bank_statement_lines', 'charge_t_account_id')) {
            Schema::table('bank_statement_lines', function (Blueprint $table): void {
                $table->unsignedBigInteger('charge_t_account_id')->nullable();
            });
        }
    }

    private function registers(): void
    {
        if (! Schema::hasTable('bank_instruments')) {
            Schema::create('bank_instruments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('kind', 12);
                $table->string('reference_no', 60);
                $table->string('bank_name', 120)->nullable();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->decimal('amount', 15, 2);
                $table->string('currency', 8)->nullable();
                $table->decimal('margin_percent', 5, 2)->default(0);
                $table->decimal('charges', 15, 2)->default(0);
                $table->date('issued_on')->nullable();
                $table->date('expires_on')->nullable()->index();
                $table->string('status', 12)->default('open')->index();
                $table->string('purpose', 255)->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('loans')) {
            Schema::create('loans', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('name', 120);
                $table->string('lender', 120)->nullable();
                $table->decimal('principal', 15, 2);
                $table->decimal('annual_rate', 6, 3)->default(0);
                $table->unsignedSmallInteger('installments');
                $table->string('frequency', 10)->default('monthly');
                $table->date('start_date');
                $table->unsignedBigInteger('loan_account_id');
                $table->unsignedBigInteger('interest_account_id');
                $table->unsignedBigInteger('bank_account_id');
                $table->unsignedBigInteger('disbursement_t_account_id')->nullable();
                $table->string('status', 10)->default('active')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('loan_schedule_rows')) {
            Schema::create('loan_schedule_rows', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('loan_id')->index();
                $table->unsignedSmallInteger('number');
                $table->date('due_on');
                $table->decimal('principal', 15, 2);
                $table->decimal('interest', 15, 2);
                $table->decimal('total', 15, 2);
                $table->date('paid_on')->nullable();
                $table->unsignedBigInteger('t_account_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tax_payments')) {
            Schema::create('tax_payments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('tax_type', 60);
                $table->string('period', 30)->nullable();
                $table->string('challan_no', 60);
                $table->string('bank_name', 120)->nullable();
                $table->decimal('amount', 15, 2);
                $table->date('paid_on');
                $table->unsignedBigInteger('tax_account_id');
                $table->unsignedBigInteger('bank_account_id');
                $table->unsignedBigInteger('t_account_id')->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    private function terms(): void
    {
        if (! Schema::hasTable('payment_terms')) {
            Schema::create('payment_terms', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 80);
                $table->unsignedSmallInteger('net_days')->default(0);
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->unsignedSmallInteger('discount_days')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('contacts') && ! Schema::hasColumn('contacts', 'payment_term_id')) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->unsignedBigInteger('payment_term_id')->nullable();
            });
        }

        if (! Schema::hasTable('early_payment_discounts')) {
            Schema::create('early_payment_discounts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->unsignedBigInteger('payment_term_id')->nullable();
                $table->decimal('amount', 15, 2);
                $table->date('applied_on');
                $table->unsignedBigInteger('t_account_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    private function yearEnd(): void
    {
        if (! Schema::hasTable('year_closings')) {
            Schema::create('year_closings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('from_year_id');
                $table->unsignedBigInteger('to_year_id');
                $table->date('closed_on');
                $table->decimal('net_result', 15, 2)->default(0);
                $table->json('summary')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'from_year_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['year_closings', 'early_payment_discounts', 'payment_terms', 'tax_payments', 'loan_schedule_rows', 'loans', 'bank_instruments', 'cheque_layouts', 'petty_cash_entries', 'petty_cash_funds'] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasColumn('contacts', 'payment_term_id')) {
            Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn('payment_term_id'));
        }

        if (Schema::hasColumn('bank_statement_lines', 'charge_t_account_id')) {
            Schema::table('bank_statement_lines', fn (Blueprint $table) => $table->dropColumn('charge_t_account_id'));
        }
    }
};
