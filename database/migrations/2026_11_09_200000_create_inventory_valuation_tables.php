<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory valuation (final closeout, task B). Nothing here changes a figure until a company records a method change:
 *
 *  - valuation_method_changes: the company's costing method from an effective date forward (average | fifo | standard). No row
 *    means weighted average, exactly as before. LIFO is not offered (not allowed under IFRS).
 *  - standard_costs: a standard cost per variation from an effective date (append only, the latest not after the day applies).
 *  - sell_line_costs: the cost locked on a sale line by a recalculation, valid while the line's date and base quantity are
 *    unchanged.
 *  - cost_recalculation_runs: every committed recalculation, for audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('valuation_method_changes')) {
            Schema::create('valuation_method_changes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('method', 12);
                $table->date('effective_from');
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'effective_from']);
            });
        }

        if (! Schema::hasTable('standard_costs')) {
            Schema::create('standard_costs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('variation_id')->index();
                $table->decimal('cost', 15, 4);
                $table->date('effective_from');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'variation_id', 'effective_from']);
            });
        }

        if (! Schema::hasTable('sell_line_costs')) {
            Schema::create('sell_line_costs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('sell_line_id')->unique();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->unsignedBigInteger('variation_id');
                $table->string('method', 12);
                $table->date('cost_date');
                $table->decimal('base_quantity', 15, 4);
                $table->decimal('unit_cost', 15, 4);
                $table->decimal('cost', 15, 2);
                $table->unsignedBigInteger('locked_by')->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cost_recalculation_runs')) {
            Schema::create('cost_recalculation_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->date('from_date');
                $table->date('to_date');
                $table->string('method', 12);
                $table->unsignedInteger('lines');
                $table->decimal('cost_before', 15, 2);
                $table->decimal('cost_after', 15, 2);
                $table->unsignedBigInteger('run_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_recalculation_runs');
        Schema::dropIfExists('sell_line_costs');
        Schema::dropIfExists('standard_costs');
        Schema::dropIfExists('valuation_method_changes');
    }
};
