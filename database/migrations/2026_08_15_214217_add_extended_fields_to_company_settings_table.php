<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('cell')->nullable()->after('phone');
            $table->string('fb_link')->nullable()->after('cell');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('search_type')->nullable()->after('time_format');
            $table->string('accounting_method')->nullable()->after('search_type');
            $table->unsignedBigInteger('default_customer')->nullable()->after('accounting_method');
            $table->string('default_pos_unit')->nullable()->after('default_customer');
            $table->boolean('update_packing_qty')->default(false)->after('default_pos_unit');
            $table->json('purchase_column')->nullable()->after('update_packing_qty');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'search_type',
                'accounting_method',
                'default_customer',
                'default_pos_unit',
                'update_packing_qty',
                'purchase_column',
            ]);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['cell', 'fb_link']);
        });
    }
};
