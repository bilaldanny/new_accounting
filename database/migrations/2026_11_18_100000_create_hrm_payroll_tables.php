<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 7, HR and payroll: the employee master (separate from users), documents with expiry, shifts, roster and attendance with raw
 * device punches, leave types and requests, salary components and structures, admin-entered statutory rates and tax slabs, bonuses,
 * employee loans, payroll runs with their lines and the company's HR settings. Additive; payroll posts to the ledger only when a
 * company switches it on and maps its accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('commission_agent_id')->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->unsignedBigInteger('manager_id')->nullable()->index();
                $table->unsignedBigInteger('shift_id')->nullable();
                $table->string('employee_no', 30);
                $table->string('first_name', 80);
                $table->string('last_name', 80)->nullable();
                $table->string('cnic', 20)->nullable();
                $table->string('gender', 10)->nullable();
                $table->date('date_of_birth')->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('designation', 100)->nullable();
                $table->string('employment_type', 20)->default('permanent');
                $table->date('join_date')->nullable();
                $table->date('exit_date')->nullable();
                $table->string('status', 15)->default('active');
                $table->string('bank_name', 100)->nullable();
                $table->string('bank_account', 50)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['company_id', 'employee_no']);
            });
        }

        if (! Schema::hasTable('employee_documents')) {
            Schema::create('employee_documents', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->string('doc_type', 40);
                $table->string('title', 150);
                $table->string('file_name', 190)->nullable();
                $table->string('path', 255)->nullable();
                $table->string('ext', 10)->nullable();
                $table->unsignedInteger('size')->default(0);
                $table->date('issued_on')->nullable();
                $table->date('expires_on')->nullable()->index();
                $table->timestamp('alerted_at')->nullable();
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hr_shifts')) {
            Schema::create('hr_shifts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 80);
                $table->string('start_time', 5);
                $table->string('end_time', 5);
                $table->unsignedSmallInteger('grace_minutes')->default(10);
                $table->string('weekly_off', 30)->default('0');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hr_rosters')) {
            Schema::create('hr_rosters', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('shift_id')->nullable();
                $table->date('work_date');
                $table->boolean('is_off')->default(false);
                $table->timestamps();
                $table->unique(['employee_id', 'work_date']);
            });
        }

        if (! Schema::hasTable('hr_attendance')) {
            Schema::create('hr_attendance', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('employee_id');
                $table->date('work_date');
                $table->string('check_in', 5)->nullable();
                $table->string('check_out', 5)->nullable();
                $table->string('status', 12)->default('present');
                $table->unsignedSmallInteger('worked_minutes')->default(0);
                $table->unsignedSmallInteger('late_minutes')->default(0);
                $table->unsignedSmallInteger('early_minutes')->default(0);
                $table->unsignedSmallInteger('overtime_minutes')->default(0);
                $table->string('source', 10)->default('manual');
                $table->string('note', 200)->nullable();
                $table->timestamps();
                $table->unique(['employee_id', 'work_date']);
            });
        }

        if (! Schema::hasTable('hr_device_tokens')) {
            Schema::create('hr_device_tokens', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 80);
                $table->string('token_hash', 64)->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hr_device_punches')) {
            Schema::create('hr_device_punches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('device_token_id')->nullable();
                $table->string('employee_no', 30);
                $table->dateTime('punched_at');
                $table->boolean('matched')->default(false);
                $table->timestamps();
                $table->unique(['company_id', 'employee_no', 'punched_at']);
            });
        }

        if (! Schema::hasTable('leave_types')) {
            Schema::create('leave_types', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 80);
                $table->decimal('days_per_year', 6, 2)->default(0);
                $table->boolean('is_paid')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('leave_requests')) {
            Schema::create('leave_requests', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('employee_id')->index();
                $table->unsignedBigInteger('leave_type_id');
                $table->date('from_date');
                $table->date('to_date');
                $table->decimal('days', 6, 2);
                $table->string('reason', 300)->nullable();
                $table->string('status', 12)->default('pending');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('decision_note', 300)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('salary_components')) {
            Schema::create('salary_components', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 80);
                $table->string('type', 10)->default('earning');
                $table->string('calc', 12)->default('fixed');
                $table->boolean('is_taxable')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('employee_salaries')) {
            Schema::create('employee_salaries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->date('effective_from');
                $table->decimal('basic', 15, 2);
                $table->text('components')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['employee_id', 'effective_from']);
            });
        }

        if (! Schema::hasTable('statutory_rates')) {
            Schema::create('statutory_rates', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('code', 30);
                $table->string('name', 100);
                $table->string('side', 10)->default('employee');
                $table->string('basis', 10)->default('basic');
                $table->decimal('rate_percent', 8, 4)->default(0);
                $table->decimal('fixed_amount', 15, 2)->default(0);
                $table->decimal('base_cap', 15, 2)->nullable();
                $table->date('effective_from');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tax_slabs')) {
            Schema::create('tax_slabs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->date('effective_from');
                $table->decimal('annual_from', 15, 2)->default(0);
                $table->decimal('annual_to', 15, 2)->nullable();
                $table->decimal('fixed_amount', 15, 2)->default(0);
                $table->decimal('rate_percent', 8, 4)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('employee_bonuses')) {
            Schema::create('employee_bonuses', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->string('period', 7);
                $table->string('title', 100);
                $table->decimal('amount', 15, 2);
                $table->boolean('is_taxable')->default(true);
                $table->unsignedBigInteger('payroll_run_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('employee_loans')) {
            Schema::create('employee_loans', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->string('kind', 10)->default('loan');
                $table->decimal('amount', 15, 2);
                $table->decimal('installment', 15, 2);
                $table->string('start_period', 7);
                $table->decimal('balance', 15, 2);
                $table->string('status', 10)->default('active');
                $table->string('note', 200)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('employee_loan_repayments')) {
            Schema::create('employee_loan_repayments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('employee_loan_id')->index();
                $table->unsignedBigInteger('payroll_run_id')->nullable()->index();
                $table->string('period', 7);
                $table->decimal('amount', 15, 2);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('period', 7);
                $table->string('status', 12)->default('draft');
                $table->text('totals')->nullable();
                $table->unsignedBigInteger('voucher_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'period']);
            });
        }

        if (! Schema::hasTable('payroll_lines')) {
            Schema::create('payroll_lines', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('payroll_run_id')->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->decimal('basic', 15, 2)->default(0);
                $table->decimal('gross', 15, 2)->default(0);
                $table->decimal('attendance_deduction', 15, 2)->default(0);
                $table->decimal('statutory_employee', 15, 2)->default(0);
                $table->decimal('statutory_employer', 15, 2)->default(0);
                $table->decimal('tax', 15, 2)->default(0);
                $table->decimal('loan_deduction', 15, 2)->default(0);
                $table->decimal('net', 15, 2)->default(0);
                $table->decimal('accrual', 15, 2)->default(0);
                $table->text('detail');
                $table->timestamps();
                $table->unique(['payroll_run_id', 'employee_id']);
            });
        }

        if (! Schema::hasTable('hr_settings')) {
            Schema::create('hr_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->text('settings');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['hr_settings', 'payroll_lines', 'payroll_runs', 'employee_loan_repayments', 'employee_loans', 'employee_bonuses', 'tax_slabs', 'statutory_rates', 'employee_salaries',
            'salary_components', 'leave_requests', 'leave_types', 'hr_device_punches', 'hr_device_tokens', 'hr_attendance', 'hr_rosters', 'hr_shifts', 'employee_documents', 'employees'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
