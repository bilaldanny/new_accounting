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
        Schema::table('t_accounts', function (Blueprint $table) {
            $table->foreignId('transaction_id')
                ->nullable()
                ->after('coa_id')
                ->constrained('transactions')
                ->cascadeOnDelete();
            $table->unique('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('t_accounts', function (Blueprint $table) {
            $table->dropUnique(['transaction_id']);
            $table->dropConstrainedForeignId('transaction_id');
        });
    }
};
