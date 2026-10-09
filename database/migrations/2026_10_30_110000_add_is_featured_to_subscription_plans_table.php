<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the superadmin mark the plan the public pricing section recommends. Nothing is highlighted unless a plan carries the flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('is_featured');
        });
    }
};
