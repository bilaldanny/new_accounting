<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider options of a messaging channel: for the Meta WhatsApp Cloud API the approved template and its language, the Graph API
 * version; for every provider the default country calling code used to turn a local number (03xx...) into an international one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messaging_settings') && ! Schema::hasColumn('messaging_settings', 'options')) {
            Schema::table('messaging_settings', function (Blueprint $table): void {
                $table->json('options')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messaging_settings', 'options')) {
            Schema::table('messaging_settings', function (Blueprint $table): void {
                $table->dropColumn('options');
            });
        }
    }
};
