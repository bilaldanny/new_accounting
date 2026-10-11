<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales & POS closeout (wave 4). Additive and nullable: a company that uses none of it sees what it saw before.
 *
 * Documents: sale_quotation_details (expiry, proforma, conversion), sales_orders + lines + invoice links, recurring_invoices +
 * runs, installment_plans + schedule. Money: customer_store_credits (named balance, refund payout), cheque_records (lifecycle).
 * People: cashiers (PIN, targets) + transactions.cashier_id, riders + dispatch_assignments, sales_targets, commission_tiers,
 * sales_routes + stops + visits. Channels: ecommerce_connections + orders, receipt_shares, messaging_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->documents();
        $this->money();
        $this->people();
        $this->channels();
    }

    private function documents(): void
    {
        if (! Schema::hasTable('sale_quotation_details')) {
            Schema::create('sale_quotation_details', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('transaction_id')->unique();
                $table->date('valid_until')->nullable();
                $table->boolean('is_proforma')->default(false);
                $table->string('converted_to_type', 10)->nullable();
                $table->unsignedBigInteger('converted_to_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_orders')) {
            Schema::create('sales_orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('contact_id')->index();
                $table->string('order_no', 30);
                $table->date('order_date');
                $table->date('expected_date')->nullable();
                $table->string('status', 20)->default('draft')->index();
                $table->unsignedBigInteger('quotation_id')->nullable();
                $table->text('note')->nullable();
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('shipping_charges', 15, 2)->default(0);
                $table->decimal('total', 15, 2)->default(0);
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'order_no']);
            });

            Schema::create('sales_order_lines', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('sales_order_id')->index();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variation_id')->nullable();
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->unsignedBigInteger('itemtype_id')->nullable();
                $table->decimal('quantity', 15, 4);
                $table->decimal('unit_price', 15, 4);
                $table->decimal('discount', 15, 4)->default(0);
                $table->decimal('invoiced_qty', 15, 4)->default(0);
                $table->timestamps();
            });

            Schema::create('sales_order_invoices', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('sales_order_id')->index();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('recurring_invoices')) {
            Schema::create('recurring_invoices', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('contact_id')->index();
                $table->string('name', 150);
                $table->string('frequency', 12);
                $table->unsignedSmallInteger('interval')->default(1);
                $table->date('next_run_on')->index();
                $table->date('ends_on')->nullable();
                $table->unsignedInteger('max_runs')->nullable();
                $table->unsignedInteger('runs')->default(0);
                $table->string('status', 10)->default('active')->index();
                $table->string('post_as', 10)->default('draft');
                $table->json('lines');
                $table->text('note')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->unsignedBigInteger('last_transaction_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });

            Schema::create('recurring_invoice_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('recurring_invoice_id')->index();
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->date('run_on');
                $table->string('status', 10);
                $table->string('message', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('installment_plans')) {
            Schema::create('installment_plans', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('contact_id')->index();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->string('kind', 12)->default('installment');
                $table->string('status', 12)->default('active')->index();
                $table->decimal('total_amount', 15, 2);
                $table->decimal('down_payment', 15, 2)->default(0);
                $table->unsignedSmallInteger('installments');
                $table->string('frequency', 10)->default('monthly');
                $table->date('first_due_on');
                $table->json('reservation_ids')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });

            Schema::create('installment_schedule', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('installment_plan_id')->index();
                $table->unsignedSmallInteger('number');
                $table->date('due_on');
                $table->decimal('amount', 15, 2);
                $table->decimal('paid_amount', 15, 2)->default(0);
                $table->date('paid_on')->nullable();
                $table->timestamps();
            });
        }
    }

    private function money(): void
    {
        if (! Schema::hasTable('customer_store_credits')) {
            Schema::create('customer_store_credits', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('contact_id')->index();
                $table->string('entry_type', 10);
                $table->decimal('amount', 15, 2);
                $table->unsignedBigInteger('sell_return_id')->nullable();
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->unsignedBigInteger('payment_id')->nullable();
                $table->string('reference', 100)->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cheque_records')) {
            Schema::create('cheque_records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('direction', 10);
                $table->string('cheque_no', 60);
                $table->string('bank_name', 120)->nullable();
                $table->unsignedBigInteger('contact_id')->nullable()->index();
                $table->decimal('amount', 15, 2);
                $table->date('cheque_date')->nullable();
                $table->date('due_date')->nullable();
                $table->string('status', 12)->default('received')->index();
                $table->date('deposited_on')->nullable();
                $table->date('cleared_on')->nullable();
                $table->date('bounced_on')->nullable();
                $table->string('bounce_reason', 255)->nullable();
                $table->unsignedBigInteger('payment_id')->nullable()->index();
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->unsignedBigInteger('bank_account_id')->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    private function people(): void
    {
        if (! Schema::hasTable('cashiers')) {
            Schema::create('cashiers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('name', 120);
                $table->string('code', 30);
                $table->string('pin_hash')->nullable();
                $table->decimal('daily_target', 15, 2)->nullable();
                $table->decimal('monthly_target', 15, 2)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('failed_attempts')->default(0);
                $table->timestamp('locked_until')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['company_id', 'code']);
            });
        }

        Schema::table('transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('transactions', 'cashier_id')) {
                $table->unsignedBigInteger('cashier_id')->nullable();
            }

            if (! Schema::hasColumn('transactions', 'rounding_adjustment')) {
                $table->decimal('rounding_adjustment', 15, 2)->nullable();
            }
        });

        if (! Schema::hasTable('riders')) {
            Schema::create('riders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('name', 120);
                $table->string('phone', 40)->nullable();
                $table->string('vehicle_no', 40)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });

            Schema::create('dispatch_assignments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('transaction_id')->unique();
                $table->unsignedBigInteger('rider_id')->index();
                $table->string('status', 10)->default('assigned')->index();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('out_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_targets')) {
            Schema::create('sales_targets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('subject_type', 10);
                $table->unsignedBigInteger('subject_id');
                $table->string('period', 7);
                $table->decimal('target_amount', 15, 2);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'subject_type', 'subject_id', 'period']);
            });

            Schema::create('commission_tiers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('commission_agent_id')->nullable();
                $table->decimal('threshold_amount', 15, 2);
                $table->decimal('rate_percent', 6, 2);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_routes')) {
            Schema::create('sales_routes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('name', 120);
                $table->unsignedTinyInteger('weekday')->nullable();
                $table->unsignedBigInteger('salesperson_id')->nullable();
                $table->unsignedBigInteger('rider_id')->nullable();
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });

            Schema::create('sales_route_stops', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('sales_route_id')->index();
                $table->unsignedBigInteger('contact_id');
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->string('note', 255)->nullable();
                $table->timestamps();
            });

            Schema::create('sales_route_visits', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('sales_route_id')->index();
                $table->unsignedBigInteger('contact_id');
                $table->date('visit_date')->index();
                $table->string('status', 10)->default('planned');
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamp('visited_at')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamps();
                $table->unique(['sales_route_id', 'contact_id', 'visit_date']);
            });
        }
    }

    private function channels(): void
    {
        if (! Schema::hasTable('ecommerce_connections')) {
            Schema::create('ecommerce_connections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('platform', 20);
                $table->string('name', 120);
                $table->string('store_url', 255)->nullable();
                $table->text('credentials')->nullable();
                $table->unsignedBigInteger('default_contact_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });

            Schema::create('ecommerce_orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('ecommerce_connection_id')->index();
                $table->string('external_id', 100);
                $table->string('status', 10)->default('new');
                $table->json('payload');
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->string('error', 500)->nullable();
                $table->timestamp('imported_at')->nullable();
                $table->timestamps();
                $table->unique(['ecommerce_connection_id', 'external_id']);
            });
        }

        if (! Schema::hasTable('receipt_shares')) {
            Schema::create('receipt_shares', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->string('token', 64)->unique();
                $table->string('channel', 10);
                $table->string('recipient', 190)->nullable();
                $table->string('status', 20);
                $table->string('error', 500)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('messaging_settings')) {
            Schema::create('messaging_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('channel', 10);
                $table->string('provider', 20);
                $table->string('endpoint', 255)->nullable();
                $table->text('credentials')->nullable();
                $table->string('sender', 60)->nullable();
                $table->boolean('is_active')->default(false);
                $table->timestamps();
                $table->unique(['company_id', 'channel']);
            });
        }

        Schema::table('company_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('company_settings', 'cash_rounding')) {
                $table->string('cash_rounding', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach ([
            'messaging_settings', 'receipt_shares', 'ecommerce_orders', 'ecommerce_connections', 'sales_route_visits', 'sales_route_stops',
            'sales_routes', 'commission_tiers', 'sales_targets', 'dispatch_assignments', 'riders', 'cashiers', 'cheque_records',
            'customer_store_credits', 'installment_schedule', 'installment_plans', 'recurring_invoice_runs', 'recurring_invoices',
            'sales_order_invoices', 'sales_order_lines', 'sales_orders', 'sale_quotation_details',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('transactions', function (Blueprint $table): void {
            foreach (['cashier_id', 'rounding_adjustment'] as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('company_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('company_settings', 'cash_rounding')) {
                $table->dropColumn('cash_rounding');
            }
        });
    }
};
