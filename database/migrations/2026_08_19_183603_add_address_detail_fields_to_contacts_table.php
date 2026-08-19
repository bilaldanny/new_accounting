<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('address_line_2')->nullable()->after('address');
            $table->string('zipcode')->nullable()->after('city_id');
            $table->string('street_name')->nullable()->after('landmark');
            $table->string('building_number')->nullable()->after('street_name');
            $table->string('secondary_number')->nullable()->after('building_number');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'address_line_2',
                'zipcode',
                'street_name',
                'building_number',
                'secondary_number',
            ]);
        });
    }
};
