<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement & Purchases closeout (wave 3). Additive: a company that uses none of it sees what it saw before.
 *
 *  - purchase_approval_rules / purchase_approval_steps: rule-based, multi-level purchase approval (no rule = the single step)
 *  - import_purchase_details: LC, customs clearing and foreign currency fields of an import purchase (information; the
 *    purchase keeps posting in the local currency)
 *  - quality_inspections / quality_inspection_lines + purchase_lines.quantity_rejected: accepted / rejected quantities; stock
 *    counts only what was accepted
 *  - supplier_payment_batches / supplier_payment_allocations: one payment allocated across several bills, with a supplier
 *    advance for what is left (every posting goes through the existing Payment paths)
 *  - inbound_shipments / inbound_shipment_events: shipment tracking of a purchase
 *  - rma_requests / rma_lines: return authorisation, inspection and return receipt with a supplier
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_approval_rules')) {
            Schema::create('purchase_approval_rules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 150);
                $table->decimal('min_amount', 15, 2)->default(0);
                $table->decimal('max_amount', 15, 2)->nullable();
                $table->json('levels');
                $table->boolean('forbid_creator')->default(true);
                $table->unsignedSmallInteger('priority')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });

            Schema::create('purchase_approval_steps', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->unsignedSmallInteger('level');
                $table->unsignedBigInteger('approved_by');
                $table->unsignedBigInteger('role_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamps();
                $table->unique(['transaction_id', 'level']);
            });
        }

        if (! Schema::hasTable('import_purchase_details')) {
            Schema::create('import_purchase_details', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('transaction_id')->unique();
                $table->string('lc_no', 100)->nullable();
                $table->string('lc_bank', 150)->nullable();
                $table->decimal('lc_amount', 15, 2)->nullable();
                $table->date('lc_opened_on')->nullable();
                $table->date('lc_expires_on')->nullable();
                $table->string('bl_no', 100)->nullable();
                $table->string('customs_ref', 100)->nullable();
                $table->date('customs_cleared_on')->nullable();
                $table->string('clearing_agent', 150)->nullable();
                $table->string('currency_code', 10)->nullable();
                $table->decimal('exchange_rate', 15, 6)->nullable();
                $table->decimal('foreign_total', 15, 2)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('quality_inspections')) {
            Schema::create('quality_inspections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->string('inspection_no', 30);
                $table->date('inspected_on');
                $table->string('result', 10)->default('passed');
                $table->text('note')->nullable();
                $table->unsignedBigInteger('inspected_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'inspection_no']);
            });

            Schema::create('quality_inspection_lines', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('quality_inspection_id')->index();
                $table->unsignedBigInteger('purchase_line_id')->index();
                $table->decimal('accepted_qty', 15, 4)->default(0);
                $table->decimal('rejected_qty', 15, 4)->default(0);
                $table->string('reason', 255)->nullable();
                $table->timestamps();
            });
        }

        Schema::table('purchase_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_lines', 'quantity_rejected')) {
                $table->decimal('quantity_rejected', 15, 4)->default(0);
            }
        });

        if (! Schema::hasTable('supplier_payment_batches')) {
            Schema::create('supplier_payment_batches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('contact_id')->index();
                $table->string('reference', 30);
                $table->date('paid_on');
                $table->unsignedBigInteger('payment_account');
                $table->string('method', 30)->default('cash');
                $table->decimal('amount', 15, 2);
                $table->decimal('advance_amount', 15, 2)->default(0);
                $table->unsignedBigInteger('advance_payment_id')->nullable();
                $table->string('status', 12)->default('completed');
                $table->text('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'reference']);
            });

            Schema::create('supplier_payment_allocations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('supplier_payment_batch_id')->index();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->unsignedBigInteger('payment_id');
                $table->decimal('amount', 15, 2);
                $table->string('kind', 10)->default('cash');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inbound_shipments')) {
            Schema::create('inbound_shipments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->string('shipment_no', 30);
                $table->string('carrier', 150)->nullable();
                $table->string('tracking_no', 100)->nullable();
                $table->string('container_no', 100)->nullable();
                $table->string('status', 15)->default('ordered')->index();
                $table->date('shipped_on')->nullable();
                $table->date('eta')->nullable();
                $table->date('arrived_on')->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'shipment_no']);
            });

            Schema::create('inbound_shipment_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('inbound_shipment_id')->index();
                $table->string('status', 15);
                $table->string('location', 150)->nullable();
                $table->string('note', 255)->nullable();
                $table->dateTime('occurred_at');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rma_requests')) {
            Schema::create('rma_requests', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('contact_id')->index();
                $table->unsignedBigInteger('transaction_id')->nullable()->index();
                $table->string('rma_no', 30);
                $table->string('supplier_rma_no', 100)->nullable();
                $table->string('status', 15)->default('requested')->index();
                $table->string('reason', 255)->nullable();
                $table->text('inspection_note')->nullable();
                $table->date('authorized_on')->nullable();
                $table->date('shipped_on')->nullable();
                $table->date('received_on')->nullable();
                $table->unsignedBigInteger('purchase_return_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'rma_no']);
            });

            Schema::create('rma_lines', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('rma_request_id')->index();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variation_id')->nullable();
                $table->decimal('quantity', 15, 4);
                $table->string('defect', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'rma_lines', 'rma_requests', 'inbound_shipment_events', 'inbound_shipments', 'supplier_payment_allocations',
            'supplier_payment_batches', 'quality_inspection_lines', 'quality_inspections', 'import_purchase_details',
            'purchase_approval_steps', 'purchase_approval_rules',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('purchase_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_lines', 'quantity_rejected')) {
                $table->dropColumn('quantity_rejected');
            }
        });
    }
};
