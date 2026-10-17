<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'notification_sound')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('notification_sound')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'notification_sound')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('notification_sound');
            });
        }
    }
};
