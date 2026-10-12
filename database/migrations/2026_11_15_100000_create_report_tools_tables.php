<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 4: saved report filters with favourites, custom reports of the report builder (whitelisted datasets, the definition is JSON),
 * and scheduled / emailed reports with their run history. Additive; no existing table changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_filters')) {
            Schema::create('report_filters', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('report_key', 80);
                $table->string('name', 120);
                $table->text('filters');
                $table->boolean('is_favourite')->default(false);
                $table->timestamps();
                $table->unique(['user_id', 'report_key', 'name']);
            });
        }

        if (! Schema::hasTable('custom_reports')) {
            Schema::create('custom_reports', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name', 120);
                $table->string('dataset', 40);
                $table->text('definition');
                $table->boolean('is_shared')->default(false);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('report_schedules')) {
            Schema::create('report_schedules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name', 120);
                $table->string('report_type', 10)->default('standard');
                $table->string('report_key', 80)->nullable();
                $table->unsignedBigInteger('custom_report_id')->nullable();
                $table->text('filters')->nullable();
                $table->string('frequency', 10)->default('daily');
                $table->unsignedTinyInteger('weekday')->nullable();
                $table->unsignedTinyInteger('day_of_month')->nullable();
                $table->text('recipients');
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_run_at')->nullable();
                $table->string('last_status', 200)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('report_schedule_runs')) {
            Schema::create('report_schedule_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('report_schedule_id')->index();
                $table->timestamp('ran_at');
                $table->string('status', 20);
                $table->unsignedInteger('row_count')->default(0);
                $table->string('message', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedule_runs');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('custom_reports');
        Schema::dropIfExists('report_filters');
    }
};
