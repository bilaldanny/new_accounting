<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request/response logging for the new public API surface only (`api/v1/*`) — not a retrofit onto the
 * app's existing, much larger internal SPA API, which would add overhead and risk to endpoints this
 * module never touches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method');
            $table->string('path');
            $table->unsignedInteger('status_code');
            $table->string('ip', 64)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
