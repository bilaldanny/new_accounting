<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_ledger_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('last_row_id');
            $table->date('last_voucher_date');
            $table->string('last_voucher_no')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_ledger_watches');
    }
};
