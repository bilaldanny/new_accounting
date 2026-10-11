<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer or supplier sends in through the portal, kept apart from the books until staff look at it:
 *
 * - `quotation` / `order`: a customer asks for products (lines in `lines`); an approval creates a quotation or a draft
 *   sale for them (`transaction_id`), nothing is posted;
 * - `bill`: a supplier sends the document of a bill they issued (file, number, date, amount, optionally the purchase
 *   order it is for); an approval only records that staff accepted it for entry.
 *
 * Also `company_settings.portal_payment_info`: the bank / payment instructions the portal shows (payments themselves
 * are not taken online).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portal_requests')) {
            Schema::create('portal_requests', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('contact_id')->index();
                $table->string('kind', 12);
                $table->string('status', 12)->default('pending')->index();
                $table->string('reference', 30)->nullable();
                $table->text('note')->nullable();
                $table->json('lines')->nullable();
                $table->unsignedBigInteger('purchase_id')->nullable();
                $table->string('bill_no', 100)->nullable();
                $table->date('bill_date')->nullable();
                $table->decimal('amount', 15, 2)->nullable();
                $table->string('file_name')->nullable();
                $table->string('file_path')->nullable();
                $table->string('file_mime', 150)->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('company_settings', 'portal_payment_info')) {
            Schema::table('company_settings', function (Blueprint $table): void {
                $table->text('portal_payment_info')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_requests');

        if (Schema::hasColumn('company_settings', 'portal_payment_info')) {
            Schema::table('company_settings', function (Blueprint $table): void {
                $table->dropColumn('portal_payment_info');
            });
        }
    }
};
