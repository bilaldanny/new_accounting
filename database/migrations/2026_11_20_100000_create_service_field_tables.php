<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 8, repair and field service: job cards (repair, field job, installation) with technician assignment, scheduling and dispatch,
 * parts, a status history, a customer signature and the invoice they lead to. The invoice is an ordinary draft sale made through the
 * sale document builder; nothing here posts to the ledger or moves stock by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_jobs')) {
            Schema::create('service_jobs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('job_no', 30);
                $table->string('type', 12)->default('repair');
                $table->unsignedBigInteger('contact_id')->nullable()->index();
                $table->string('title', 150);
                $table->text('description')->nullable();
                $table->string('item_description', 200)->nullable();
                $table->string('serial_no', 100)->nullable();
                $table->unsignedBigInteger('warranty_claim_id')->nullable();
                $table->boolean('is_warranty')->default(false);
                $table->unsignedBigInteger('technician_id')->nullable()->index();
                $table->string('priority', 8)->default('normal');
                $table->string('status', 14)->default('new')->index();
                $table->dateTime('scheduled_at')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->string('site_address', 255)->nullable();
                $table->decimal('service_charge', 15, 2)->default(0);
                $table->unsignedBigInteger('service_product_id')->nullable();
                $table->unsignedBigInteger('sale_id')->nullable();
                $table->string('signature_path', 255)->nullable();
                $table->string('signed_by', 100)->nullable();
                $table->dateTime('signed_at')->nullable();
                $table->text('report_notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'job_no']);
            });
        }

        if (! Schema::hasTable('service_job_parts')) {
            Schema::create('service_job_parts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('service_job_id')->index();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variation_id');
                $table->decimal('quantity', 15, 4);
                $table->decimal('unit_price', 15, 2)->nullable();
                $table->string('note', 200)->nullable();
                $table->unsignedBigInteger('added_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('service_job_events')) {
            Schema::create('service_job_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('service_job_id')->index();
                $table->string('event', 20);
                $table->string('from_status', 14)->nullable();
                $table->string('to_status', 14)->nullable();
                $table->string('note', 300)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['service_job_events', 'service_job_parts', 'service_jobs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
