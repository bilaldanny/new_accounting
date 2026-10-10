<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Period lock: books closed through a date. Additive only: a new append-only table (the current lock of a company is its latest
 * row; a row with no date means "not locked") and two nullable columns on company settings for the back-dating / future-dating
 * restriction (NULL = no restriction, so every company behaves exactly as before until it sets one).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('period_locks')) {
            Schema::create('period_locks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->date('locked_through')->nullable();
                $table->string('action', 20);
                $table->string('reason', 500)->nullable();
                $table->unsignedBigInteger('locked_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'branch_id', 'id']);
            });
        }

        Schema::table('company_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('company_settings', 'max_backdate_days')) {
                $table->unsignedSmallInteger('max_backdate_days')->nullable();
            }

            if (! Schema::hasColumn('company_settings', 'allow_future_dates')) {
                $table->boolean('allow_future_dates')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['max_backdate_days', 'allow_future_dates']);
        });

        Schema::dropIfExists('period_locks');
    }
};
