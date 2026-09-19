<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journal entry approval can end in a rejection, which is not the same as a cancellation: adds
 * `rejected` to the voucher status and records who rejected it and when. Existing rows and their
 * statuses are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_accounts', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'cancelled', 'rejected'])->default('pending')->change();
        });

        Schema::table('t_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('rejected_by')->nullable()->after('cancelled_by')->index();
            $table->dateTime('rejected_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        DB::table('t_accounts')->where('status', 'rejected')->update(['status' => 'cancelled']);

        Schema::table('t_accounts', function (Blueprint $table) {
            $table->dropIndex(['rejected_by']);
            $table->dropColumn(['rejected_by', 'rejected_at']);
        });

        Schema::table('t_accounts', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'cancelled'])->default('pending')->change();
        });
    }
};
