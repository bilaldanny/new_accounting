<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The journal schedule engine (final closeout, task C): recurring and reversing journals, recurring expenses, prepaid / deferred
 * amortization and accruals with automatic reversal. A schedule posts through AccountsVoucher, so the journal / expense approval
 * rule, the period lock and the voucher numbering all apply.
 *
 *  - journal_schedules: the template and the rule. `kind` is recurring | reversing | accrual | amortization | expense.
 *  - journal_schedule_runs: one row per posted occurrence (kind `post`) and per reversal (kind `reversal`), unique per schedule,
 *    day and kind, so running again never posts twice.
 *  - t_accounts.reversal_of_id / reversed_by_id: the link between an entry and the entry that reverses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_schedules')) {
            Schema::create('journal_schedules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('kind', 14);
                $table->string('name', 120);
                $table->string('frequency', 8)->default('monthly');
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->unsignedInteger('max_runs')->nullable();
                $table->boolean('auto_post')->default(true);
                $table->boolean('auto_reverse')->default(false);
                $table->string('status', 10)->default('active')->index();
                $table->json('lines')->nullable();
                $table->json('meta')->nullable();
                $table->string('blocked_reason', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('journal_schedule_runs')) {
            Schema::create('journal_schedule_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('journal_schedule_id')->index();
                $table->date('period_date');
                $table->string('kind', 10)->default('post');
                $table->unsignedBigInteger('t_account_id')->nullable();
                $table->decimal('amount', 15, 2)->default(0);
                $table->string('state', 10)->default('posted');
                $table->timestamps();
                $table->unique(['journal_schedule_id', 'period_date', 'kind'], 'journal_schedule_runs_unique');
            });
        }

        if (Schema::hasTable('t_accounts') && ! Schema::hasColumn('t_accounts', 'reversal_of_id')) {
            Schema::table('t_accounts', function (Blueprint $table): void {
                $table->unsignedBigInteger('reversal_of_id')->nullable()->index();
                $table->unsignedBigInteger('reversed_by_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('t_accounts', 'reversal_of_id')) {
            Schema::table('t_accounts', function (Blueprint $table): void {
                $table->dropColumn(['reversal_of_id', 'reversed_by_id']);
            });
        }

        Schema::dropIfExists('journal_schedule_runs');
        Schema::dropIfExists('journal_schedules');
    }
};
