<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_accounts', function (Blueprint $table) {
            $table->unique(['company_id', 'branch_id', 'voucher_no'], 't_accounts_company_branch_voucher_unique');
        });
    }

    public function down(): void
    {
        Schema::table('t_accounts', function (Blueprint $table) {
            $table->dropUnique('t_accounts_company_branch_voucher_unique');
        });
    }
};
