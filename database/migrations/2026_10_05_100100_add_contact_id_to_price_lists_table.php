<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes Module 2's "Customer-Specific Price Lists" and "Supplier-Specific Price Lists" gaps: a price
 * list can now optionally target one contact (customer or supplier) instead of, or alongside, a brand.
 * `price_lists` itself is not yet consumed anywhere in the sell/purchase pricing calculation (confirmed
 * zero references in Transaction.php or any Service) — that automatic-application engine is a separate,
 * pre-existing Module 3 gap ("Price Lists & Selling Price Groups" is itself only Partial/CRUD there) and
 * stays out of this change's scope; this migration only closes the specific gap the Contacts module
 * items named: a price list can be linked to a contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('brand_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });
    }
};
