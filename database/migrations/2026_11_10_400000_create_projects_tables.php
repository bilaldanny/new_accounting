<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project / job costing (final closeout, task C): a project master, separate from cost centers (an optional link to one), and a
 * `project_id` dimension on sale lines, purchase lines and voucher lines. A tag is a dimension, not a posting: the ledger is
 * exactly what it was, and a document or voucher without a project is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('code', 30);
                $table->string('name', 150);
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->unsignedBigInteger('cost_center_id')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->decimal('budget_revenue', 15, 2)->default(0);
                $table->decimal('budget_cost', 15, 2)->default(0);
                $table->string('status', 10)->default('active');
                $table->text('description')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'code']);
            });
        }

        foreach (['sell_lines', 'purchase_lines', 't_account_details'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'project_id')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->unsignedBigInteger('project_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['sell_lines', 'purchase_lines', 't_account_details'] as $name) {
            if (Schema::hasColumn($name, 'project_id')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->dropColumn('project_id');
                });
            }
        }

        Schema::dropIfExists('projects');
    }
};
