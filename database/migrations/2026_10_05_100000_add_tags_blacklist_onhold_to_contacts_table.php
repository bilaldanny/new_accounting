<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes two Module 2 (Contacts) gaps from the Priority Roadmap: Contact Tags & Custom Categorization
 * (`tags`, a simple JSON array — no new package, no separate pivot table needed for free-text tagging)
 * and Blacklist / On-Hold Customer Control (`is_blacklisted`, `is_on_hold`, enforced in
 * `Transaction::createSell()`/`createPurchase()` the same way Module 17's company limits are).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('type');
            $table->boolean('is_blacklisted')->default(false)->after('tags');
            $table->boolean('is_on_hold')->default(false)->after('is_blacklisted');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['tags', 'is_blacklisted', 'is_on_hold']);
        });
    }
};
