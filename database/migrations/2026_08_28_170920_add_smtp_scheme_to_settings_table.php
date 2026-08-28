<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'smtp_scheme')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->string('smtp_scheme')->nullable()->after('smtp_encryption');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('settings', 'smtp_scheme')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('smtp_scheme');
        });
    }
};
