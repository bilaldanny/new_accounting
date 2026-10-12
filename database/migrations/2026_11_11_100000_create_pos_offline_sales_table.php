<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline POS sales (final closeout, task D). Every sale rung up offline carries a client UUID and is replayed to the server once,
 * as one unit (sale and payment), idempotently: a repeat of the same UUID returns what the first one did and posts nothing.
 *
 *  - status: synced (posted), conflict (parked for a supervisor: the server did not accept it as sent), failed (an error; the
 *    terminal may send it again), discarded (a supervisor refused it).
 *  - oversold: the lines the sale took past the stock the server held at sync (allowed offline, flagged).
 *  - offline_receipt_no: the provisional number printed at the terminal; the real invoice number is assigned at sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_offline_sales')) {
            return;
        }

        Schema::create('pos_offline_sales', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('client_uuid', 64);
            $table->string('device_id', 64)->nullable();
            $table->string('offline_receipt_no', 60)->nullable();
            $table->dateTime('sold_at')->nullable();
            $table->string('catalog_version', 64)->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->json('payload');
            $table->string('status', 10)->default('failed')->index();
            $table->string('conflict_code', 30)->nullable();
            $table->json('conflict_detail')->nullable();
            $table->json('oversold')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable()->index();
            $table->string('invoice_no', 100)->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_offline_sales');
    }
};
