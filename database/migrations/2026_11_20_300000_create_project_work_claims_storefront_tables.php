<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 8, the last three advanced modules: project milestones and tasks (on the projects that already carry budget and costing),
 * employee expense claims (submitted, approved, then paid through a voucher) and the e-commerce storefront (published products and
 * the orders that come in from the public shop, kept apart from the books until staff turn one into a sale).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_milestones')) {
            Schema::create('project_milestones', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('project_id')->index();
                $table->string('name', 150);
                $table->date('due_date')->nullable();
                $table->decimal('amount', 15, 2)->default(0);
                $table->string('status', 10)->default('pending');
                $table->date('completed_on')->nullable();
                $table->string('billing_note', 200)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('project_tasks')) {
            Schema::create('project_tasks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('project_id')->index();
                $table->unsignedBigInteger('milestone_id')->nullable()->index();
                $table->string('title', 150);
                $table->unsignedBigInteger('assignee_id')->nullable()->index();
                $table->date('due_date')->nullable();
                $table->string('status', 8)->default('todo');
                $table->decimal('estimated_hours', 8, 2)->default(0);
                $table->decimal('spent_hours', 8, 2)->default(0);
                $table->date('completed_on')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('expense_claims')) {
            Schema::create('expense_claims', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('claim_no', 30);
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->string('title', 150);
                $table->date('claim_date');
                $table->string('status', 10)->default('draft')->index();
                $table->decimal('total', 15, 2)->default(0);
                $table->string('note', 300)->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('decision_note', 300)->nullable();
                $table->date('paid_on')->nullable();
                $table->unsignedBigInteger('payment_account_id')->nullable();
                $table->unsignedBigInteger('voucher_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();
                $table->unique(['company_id', 'claim_no']);
            });
        }

        if (! Schema::hasTable('expense_claim_lines')) {
            Schema::create('expense_claim_lines', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('expense_claim_id')->index();
                $table->date('expense_date');
                $table->unsignedBigInteger('account_id');
                $table->string('description', 200);
                $table->decimal('amount', 15, 2);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('expense_claim_receipts')) {
            Schema::create('expense_claim_receipts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('expense_claim_id')->index();
                $table->string('file_name', 190);
                $table->string('path', 255);
                $table->string('ext', 10);
                $table->unsignedInteger('size')->default(0);
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('storefront_settings')) {
            Schema::create('storefront_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->string('code', 40)->unique();
                $table->boolean('enabled')->default(false);
                $table->string('title', 100)->nullable();
                $table->string('intro', 300)->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('default_contact_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('storefront_products')) {
            Schema::create('storefront_products', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variation_id');
                $table->decimal('price', 15, 2)->nullable();
                $table->boolean('is_published')->default(true);
                $table->timestamps();
                $table->unique(['company_id', 'product_id', 'variation_id']);
            });
        }

        if (! Schema::hasTable('storefront_orders')) {
            Schema::create('storefront_orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('order_no', 30);
                $table->string('customer_name', 100);
                $table->string('phone', 30);
                $table->string('email', 150)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('note', 300)->nullable();
                $table->text('lines');
                $table->decimal('total', 15, 2)->default(0);
                $table->string('status', 10)->default('new')->index();
                $table->unsignedBigInteger('sale_id')->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'order_no']);
            });
        }
    }

    public function down(): void
    {
        foreach (['storefront_orders', 'storefront_products', 'storefront_settings', 'expense_claim_receipts', 'expense_claim_lines', 'expense_claims', 'project_tasks', 'project_milestones'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
