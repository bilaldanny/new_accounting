<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Early installs were seeded with the logo of another product (assets/images/logo-img.png) as the system logo. A row that still
 * holds that default now gets the text-only placeholder; a logo someone uploaded is never touched.
 */
return new class extends Migration
{
    private const LEGACY = ['assets/images/logo-img.png', '/assets/images/logo-img.png'];

    private const PLACEHOLDER = 'assets/images/logo-placeholder.svg';

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->whereIn('system_logo', self::LEGACY)->update(['system_logo' => self::PLACEHOLDER]);

        foreach (['email_logo', 'login_logo'] as $column) {
            if (Schema::hasColumn('settings', $column)) {
                DB::table('settings')->whereIn($column, self::LEGACY)->update([$column => null]);
            }
        }
    }

    public function down(): void
    {
        // The old default belonged to another product; it is not put back.
    }
};
