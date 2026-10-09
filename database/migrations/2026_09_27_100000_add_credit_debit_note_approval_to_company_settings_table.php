<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The auto-approval toggle for the new Credit/Debit Note voucher family, next to the other manual
 * voucher toggles (Settings > Approval): with it on a new note is approved on save, with it off it
 * waits in the approval list. Unlike 2026_09_20_100000_add_voucher_approval_toggles..., Credit/Debit
 * Note is a brand new feature with no prior behaviour to preserve, so every company (existing and new)
 * gets the column default, off (approval required), matching the owner's confirmed decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('credit_debit_note_approval')->default(0)->after('fund_transfer_approval');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('credit_debit_note_approval');
        });
    }
};
