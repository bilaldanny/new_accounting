<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory & Warehouse closeout (wave 2). Additive and nullable: a company that uses none of it sees what it saw before.
 *
 *  - stock_reservations: quantity held for a customer or an order; reduces what a sale may take only when the company
 *    switches `reservation_mode` to `enforce`
 *  - warehouse_tasks / warehouse_task_lines: pick, pack and put-away work lists (a record of the work, stock moves through
 *    the issue and receiving notes as before)
 *  - stock_writeoffs: the reason (damage, expired, near-expiry disposal, wastage) of a stock adjustment that wrote stock off
 *  - inventory_closes: the history of the inventory closing lock
 *  - company_settings.negative_stock_mode / reservation_mode, products.negative_stock (NULL = as before)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_reservations')) {
            Schema::create('stock_reservations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('variation_id')->nullable();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->decimal('quantity', 15, 4);
                $table->string('reference', 100)->nullable();
                $table->string('status', 12)->default('active')->index();
                $table->date('expires_on')->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('reserved_by')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('warehouse_tasks')) {
            Schema::create('warehouse_tasks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->string('task_no', 30);
                $table->string('type', 10);
                $table->unsignedBigInteger('transaction_id')->nullable()->index();
                $table->string('status', 12)->default('open')->index();
                $table->unsignedBigInteger('assigned_to')->nullable();
                $table->text('note')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'task_no']);
            });

            Schema::create('warehouse_task_lines', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('warehouse_task_id')->index();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variation_id')->nullable();
                $table->decimal('quantity', 15, 4);
                $table->decimal('quantity_done', 15, 4)->default(0);
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stock_writeoffs')) {
            Schema::create('stock_writeoffs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('adjustment_id')->index();
                $table->string('reason', 20)->index();
                $table->decimal('total_quantity', 15, 4)->default(0);
                $table->decimal('total_value', 15, 2)->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inventory_closes')) {
            Schema::create('inventory_closes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->date('locked_through')->nullable();
                $table->string('action', 10);
                $table->string('reason', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('company_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('company_settings', 'negative_stock_mode')) {
                $table->string('negative_stock_mode', 10)->nullable();
            }

            if (! Schema::hasColumn('company_settings', 'reservation_mode')) {
                $table->string('reservation_mode', 10)->nullable();
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'negative_stock')) {
                $table->string('negative_stock', 10)->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['inventory_closes', 'stock_writeoffs', 'warehouse_task_lines', 'warehouse_tasks', 'stock_reservations'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('company_settings', function (Blueprint $table): void {
            foreach (['negative_stock_mode', 'reservation_mode'] as $column) {
                if (Schema::hasColumn('company_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'negative_stock')) {
                $table->dropColumn('negative_stock');
            }
        });
    }
};
