<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sanctum 4 lets a token expire (`expires_at`); the table was created by the older Sanctum migration
     * without the column, so an API key with an expiry could not be stored. Additive: existing tokens (there are
     * none on live data) keep a null expiry, meaning they never expire.
     */
    public function up(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('last_used_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
