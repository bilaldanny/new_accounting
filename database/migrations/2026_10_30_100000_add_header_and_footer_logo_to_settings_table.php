<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more logos in Software Settings, for the header and the footer of the public website (landing, register, blog). Either
 * one left empty falls back: footer to header, header to the software logo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('header_logo')->nullable()->after('login_logo');
            $table->string('footer_logo')->nullable()->after('header_logo');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['header_logo', 'footer_logo']);
        });
    }
};
