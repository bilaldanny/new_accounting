<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The server side of offline mode: one row for every entry a device sends, whatever kind it is. The client UUID is the idempotency
 * key (unique per company), so a repeated send returns what the first one did and posts nothing. See docs/offline-mode-design.md.
 *
 *  - status: synced (posted), conflict (the server refused it as sent: kept, with the reason, for the user to edit and retry or discard),
 *    discarded (the user dropped it, with an audit log line).
 *  - result_type / result_id / real_ref: the document the entry became and its real number (the device keeps a provisional OFF- number).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('offline_sync_entries')) {
            return;
        }

        Schema::create('offline_sync_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('device_id', 64)->nullable();
            $table->string('client_uuid', 64);
            $table->string('kind', 30);
            $table->string('provisional_ref', 60)->nullable();
            $table->string('status', 12)->default('conflict');
            $table->string('conflict_code', 40)->nullable();
            $table->text('error')->nullable();
            $table->json('payload');
            $table->string('result_type', 40)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->string('real_ref', 60)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('created_at_client')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'client_uuid']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync_entries');
    }
};
