<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('supplier_gl_id')->nullable()->after('gl_id');
            $table->string('customer_gl_id')->nullable()->after('supplier_gl_id');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['supplier_gl_id', 'customer_gl_id']);
        });
    }
};
