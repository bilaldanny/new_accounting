<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a company's customer credit limit is enforced on a sale: `hard` (the default, a sale past the limit is refused),
 * `warn` (the user is told and may go on with a reason, which is audited) or `off`. NULL is read as `hard`, so a
 * company that never touches the setting behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('company_settings', 'credit_limit_mode')) {
            return;
        }

        Schema::table('company_settings', function (Blueprint $table): void {
            $table->string('credit_limit_mode', 10)->nullable()->after('limit_account');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_settings', 'credit_limit_mode')) {
            Schema::table('company_settings', function (Blueprint $table): void {
                $table->dropColumn('credit_limit_mode');
            });
        }
    }
};
