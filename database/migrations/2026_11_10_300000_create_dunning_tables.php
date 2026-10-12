<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice dunning and collection management (final closeout, task C). Nothing posts to the ledger. Separate from the tenant
 * subscription dunning, which is about the application's own customers.
 *
 *  - dunning_levels: up to three levels per company: days past due, editable subject and body templates.
 *  - dunning_events: the log: every reminder made (invoice, level, channel, outcome, the exact text), so a level is not sent twice.
 *  - collection_cases: the collection workflow status of a customer (open, promised, disputed, escalated, closed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dunning_levels')) {
            Schema::create('dunning_levels', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedTinyInteger('level');
                $table->string('name', 60);
                $table->unsignedInteger('days_past_due');
                $table->string('subject', 150);
                $table->text('body');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['company_id', 'level']);
            });
        }

        if (! Schema::hasTable('dunning_events')) {
            Schema::create('dunning_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('contact_id')->index();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->unsignedTinyInteger('level');
                $table->string('channel', 10)->default('email');
                $table->string('status', 10);
                $table->string('recipient', 150)->nullable();
                $table->string('subject', 200);
                $table->text('body');
                $table->decimal('amount', 15, 2)->default(0);
                $table->date('promised_on')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('collection_cases')) {
            Schema::create('collection_cases', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('contact_id');
                $table->string('status', 10)->default('open');
                $table->date('promised_on')->nullable();
                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'contact_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_cases');
        Schema::dropIfExists('dunning_events');
        Schema::dropIfExists('dunning_levels');
    }
};
