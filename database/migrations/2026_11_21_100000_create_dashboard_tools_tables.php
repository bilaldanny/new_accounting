<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 1b, the dashboard rows the first pass left open: the daily business summary (settings and what was sent), layouts a company
 * sets for a role, and the KPI widgets a user builds from the figures of the reports they may open. Additive and inert until used.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_summary_settings')) {
            Schema::create('daily_summary_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->unsignedTinyInteger('send_hour')->default(20);
                $table->text('channels')->nullable();
                $table->text('recipients')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('daily_summary_runs')) {
            Schema::create('daily_summary_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->date('run_date');
                $table->string('channel', 10);
                $table->string('recipient', 150);
                $table->string('status', 10);
                $table->string('error', 300)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['company_id', 'run_date', 'channel', 'recipient'], 'daily_summary_runs_unique');
            });
        }

        if (! Schema::hasTable('dashboard_role_layouts')) {
            Schema::create('dashboard_role_layouts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('role_id');
                $table->text('layout');
                $table->timestamps();
                $table->unique(['company_id', 'role_id']);
            });
        }

        if (! Schema::hasTable('dashboard_kpis')) {
            Schema::create('dashboard_kpis', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('report_key', 40);
                $table->string('figure_key', 60);
                $table->string('period', 10)->default('month');
                $table->string('label', 80);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['dashboard_kpis', 'dashboard_role_layouts', 'daily_summary_runs', 'daily_summary_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
