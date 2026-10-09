<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The auto-approval toggle for Purchase Requisitions, next to the other approval toggles
 * (Settings > Approval): with it on a new requisition is approved on save, with it off it waits in
 * the approval list. A brand new feature with no prior behaviour to preserve, so every company
 * (existing and new) gets the column default, off (approval required).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('purchase_requisition_approval')->default(0)->after('credit_debit_note_approval');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('purchase_requisition_approval');
        });
    }
};
