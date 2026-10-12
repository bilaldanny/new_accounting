<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-currency vouchers (final closeout, task C). Off by default: a company that never switches it on sees no change at all.
 *
 *  - company_settings.multi_currency_vouchers / fx_gain_loss_account_id: the switch and the account realised gains and losses go to.
 *  - t_account_details.currency_id / exchange_rate / foreign_amount: the foreign amount of a voucher line and the rate it was
 *    entered at (foreign units per one unit of the base currency, like Currency Rates). debit / credit stay the base amount, the
 *    posted truth, so every existing report is unchanged.
 *  - currency_rate_history: dated rates; the current rate of Currency Rates stays the fallback.
 *  - fx_settlements: a foreign line settled at another rate, with the realised gain or loss posted for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_settings') && ! Schema::hasColumn('company_settings', 'multi_currency_vouchers')) {
            Schema::table('company_settings', function (Blueprint $table): void {
                $table->boolean('multi_currency_vouchers')->default(false);
                $table->unsignedBigInteger('fx_gain_loss_account_id')->nullable();
            });
        }

        if (Schema::hasTable('t_account_details') && ! Schema::hasColumn('t_account_details', 'currency_id')) {
            Schema::table('t_account_details', function (Blueprint $table): void {
                $table->unsignedBigInteger('currency_id')->nullable();
                $table->decimal('exchange_rate', 16, 6)->nullable();
                $table->decimal('foreign_amount', 15, 2)->nullable();
            });
        }

        if (! Schema::hasTable('currency_rate_history')) {
            Schema::create('currency_rate_history', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('currency_id');
                $table->date('effective_on');
                $table->decimal('rate', 16, 6);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'currency_id', 'effective_on'], 'currency_rate_history_unique');
            });
        }

        if (! Schema::hasTable('fx_settlements')) {
            Schema::create('fx_settlements', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('t_account_detail_id')->index();
                $table->unsignedBigInteger('currency_id');
                $table->decimal('foreign_amount', 15, 2);
                $table->decimal('original_rate', 16, 6);
                $table->decimal('settlement_rate', 16, 6);
                $table->decimal('base_original', 15, 2);
                $table->decimal('base_settled', 15, 2);
                $table->decimal('fx_amount', 15, 2);
                $table->date('settled_on');
                $table->unsignedBigInteger('t_account_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_settlements');
        Schema::dropIfExists('currency_rate_history');

        if (Schema::hasColumn('t_account_details', 'currency_id')) {
            Schema::table('t_account_details', function (Blueprint $table): void {
                $table->dropColumn(['currency_id', 'exchange_rate', 'foreign_amount']);
            });
        }

        if (Schema::hasColumn('company_settings', 'multi_currency_vouchers')) {
            Schema::table('company_settings', function (Blueprint $table): void {
                $table->dropColumn(['multi_currency_vouchers', 'fx_gain_loss_account_id']);
            });
        }
    }
};
