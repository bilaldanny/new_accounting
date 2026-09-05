<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('t_account_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('t_account_id')->constrained('t_accounts')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_url')->nullable();
            $table->string('ext', 20)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_account_attachments');
    }
};
