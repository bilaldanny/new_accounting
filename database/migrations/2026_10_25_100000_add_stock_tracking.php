<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serial / batch tracking as an optional layer next to stock, not part of it. `products.tracking_type` says how a product is
 * tracked (`none` for every existing product, so nothing changes for them). A serial or a batch is an identity
 * (`stock_serials`, `stock_batches`); what happens to it is a list of movements, each tied to the document and the document
 * line that caused it. Availability is worked out from the movements of documents that are still live, the way stock itself is
 * derived, so deleting, restoring or finalising a document needs no clean-up here. `StockMovements` is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('tracking_type', 10)->default('none')->after('type');
        });

        Schema::create('stock_serials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->string('serial_no');
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'serial_no']);
        });

        Schema::create('stock_serial_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('serial_id')->constrained('stock_serials')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->cascadeOnDelete();
            $table->string('line_table', 20)->nullable();
            $table->unsignedBigInteger('line_id')->nullable();
            $table->string('kind', 20);
            $table->smallInteger('direction');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('moved_at')->useCurrent();
            $table->timestamps();

            $table->index(['line_table', 'line_id']);
            $table->index('kind');
        });

        Schema::create('stock_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->string('batch_no');
            $table->date('expiry_date')->nullable()->index();
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'batch_no']);
        });

        Schema::create('stock_batch_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('stock_batches')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->cascadeOnDelete();
            $table->string('line_table', 20)->nullable();
            $table->unsignedBigInteger('line_id')->nullable();
            $table->string('kind', 20);
            $table->decimal('qty', 15, 4);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('moved_at')->useCurrent();
            $table->timestamps();

            $table->index(['line_table', 'line_id']);
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batch_movements');
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('stock_serial_movements');
        Schema::dropIfExists('stock_serials');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('tracking_type');
        });
    }
};
