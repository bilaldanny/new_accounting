<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 8, manufacturing: versioned multi-level bills of materials with outputs (by-products, co-products) and operations, the master
 * production schedule, work orders with their material issues, receipts, scrap, direct labour / overhead and quality checks, and the
 * company's manufacturing settings. Stock moves through completed stock adjustments (like kitting); the ledger only moves when a
 * company switches manufacturing posting on and maps its accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('boms')) {
            Schema::create('boms', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('variation_id')->nullable();
                $table->string('code', 30);
                $table->string('name', 150);
                $table->unsignedInteger('version')->default(1);
                $table->string('status', 10)->default('draft');
                $table->decimal('output_qty', 15, 4)->default(1);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->string('notes', 300)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('activated_by')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'product_id', 'version']);
            });
        }

        if (! Schema::hasTable('bom_items')) {
            Schema::create('bom_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('bom_id')->index();
                $table->unsignedBigInteger('component_product_id');
                $table->unsignedBigInteger('component_variation_id');
                $table->decimal('quantity', 15, 4);
                $table->decimal('scrap_percent', 6, 2)->default(0);
                $table->string('notes', 200)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bom_outputs')) {
            Schema::create('bom_outputs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('bom_id')->index();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variation_id');
                $table->string('kind', 10)->default('byproduct');
                $table->decimal('quantity', 15, 4);
                $table->decimal('cost_share_percent', 6, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bom_operations')) {
            Schema::create('bom_operations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('bom_id')->index();
                $table->unsignedSmallInteger('sequence')->default(1);
                $table->string('name', 100);
                $table->decimal('minutes', 10, 2)->default(0);
                $table->decimal('labor_rate_per_hour', 15, 2)->default(0);
                $table->decimal('overhead_rate_per_hour', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('production_plans')) {
            Schema::create('production_plans', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variation_id')->nullable();
                $table->date('period_start');
                $table->decimal('planned_qty', 15, 4);
                $table->string('note', 200)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'branch_id', 'product_id', 'period_start'], 'production_plans_unique');
            });
        }

        if (! Schema::hasTable('work_orders')) {
            Schema::create('work_orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('wo_no', 30);
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('variation_id');
                $table->unsignedBigInteger('bom_id');
                $table->unsignedInteger('bom_version');
                $table->decimal('planned_qty', 15, 4);
                $table->decimal('produced_qty', 15, 4)->default(0);
                $table->decimal('scrap_qty', 15, 4)->default(0);
                $table->string('status', 12)->default('draft');
                $table->date('planned_start')->nullable();
                $table->date('planned_end')->nullable();
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->decimal('material_cost', 15, 2)->default(0);
                $table->decimal('labor_cost', 15, 2)->default(0);
                $table->decimal('overhead_cost', 15, 2)->default(0);
                $table->decimal('absorbed_cost', 15, 2)->default(0);
                $table->decimal('scrap_loss', 15, 2)->default(0);
                $table->decimal('fg_unit_cost', 15, 4)->nullable();
                $table->string('notes', 300)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('released_by')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'wo_no']);
            });
        }

        if (! Schema::hasTable('work_order_materials')) {
            Schema::create('work_order_materials', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('work_order_id')->index();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variation_id');
                $table->decimal('required_qty', 15, 4);
                $table->decimal('issued_qty', 15, 4)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('work_order_issues')) {
            Schema::create('work_order_issues', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('work_order_id')->index();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variation_id');
                $table->decimal('quantity', 15, 4);
                $table->decimal('unit_cost', 15, 4)->default(0);
                $table->unsignedBigInteger('stock_transaction_id')->nullable();
                $table->unsignedBigInteger('voucher_id')->nullable();
                $table->date('issued_on');
                $table->unsignedBigInteger('issued_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('work_order_receipts')) {
            Schema::create('work_order_receipts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('work_order_id')->index();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variation_id');
                $table->string('kind', 10)->default('finished');
                $table->decimal('quantity', 15, 4);
                $table->decimal('unit_cost', 15, 4)->default(0);
                $table->decimal('total_cost', 15, 2)->default(0);
                $table->unsignedBigInteger('stock_transaction_id')->nullable();
                $table->unsignedBigInteger('voucher_id')->nullable();
                $table->date('received_on');
                $table->unsignedBigInteger('received_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('work_order_scraps')) {
            Schema::create('work_order_scraps', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('work_order_id')->index();
                $table->decimal('quantity', 15, 4);
                $table->boolean('is_normal')->default(true);
                $table->string('reason', 200)->nullable();
                $table->decimal('cost', 15, 2)->default(0);
                $table->unsignedBigInteger('voucher_id')->nullable();
                $table->date('recorded_on');
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('work_order_labor')) {
            Schema::create('work_order_labor', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('work_order_id')->index();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('operation', 100);
                $table->decimal('hours', 10, 2);
                $table->decimal('labor_rate', 15, 2)->default(0);
                $table->decimal('overhead_rate', 15, 2)->default(0);
                $table->decimal('labor_cost', 15, 2)->default(0);
                $table->decimal('overhead_cost', 15, 2)->default(0);
                $table->unsignedBigInteger('voucher_id')->nullable();
                $table->date('worked_on');
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('work_order_checks')) {
            Schema::create('work_order_checks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('work_order_id')->index();
                $table->string('check_name', 100);
                $table->string('result', 6)->default('pass');
                $table->decimal('qty_inspected', 15, 4)->default(0);
                $table->decimal('qty_failed', 15, 4)->default(0);
                $table->string('note', 200)->nullable();
                $table->unsignedBigInteger('inspected_by')->nullable();
                $table->date('inspected_on');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('manufacturing_settings')) {
            Schema::create('manufacturing_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->text('settings');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['manufacturing_settings', 'work_order_checks', 'work_order_labor', 'work_order_scraps', 'work_order_receipts', 'work_order_issues', 'work_order_materials',
            'work_orders', 'production_plans', 'bom_operations', 'bom_outputs', 'bom_items', 'boms'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
