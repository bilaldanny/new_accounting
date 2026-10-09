<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts & Finance Phase 3: the inter-branch transfer account of an asset category, which an asset transfer
 * between branches posts through (see Services\FixedAssetTransfer). Nullable, so existing categories are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('transfer_coa_id')->nullable()->after('disposal_coa_id');
        });
    }

    public function down(): void
    {
        Schema::table('asset_categories', function (Blueprint $table) {
            $table->dropColumn('transfer_coa_id');
        });
    }
};
