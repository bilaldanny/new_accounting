<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 6, settings and system setup: branch hierarchy (`branches.parent_id`), consolidated business settings (number format, POS,
 * language), notification templates, web push subscriptions, platform system settings (VAPID keys, remote backup), import history
 * with undo, data retention rules, franchise groups and help articles. Additive; no existing column changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branches') && ! Schema::hasColumn('branches', 'parent_id')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->unsignedBigInteger('parent_id')->nullable()->index();
            });
        }

        if (! Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->longText('settings');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('notification_templates')) {
            Schema::create('notification_templates', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('kind', 60);
                $table->string('title', 160)->nullable();
                $table->text('body')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['company_id', 'kind']);
            });
        }

        if (! Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('endpoint_hash', 64)->unique();
                $table->text('endpoint');
                $table->string('p256dh', 200);
                $table->string('auth', 100);
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 80)->unique();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('import_runs')) {
            Schema::create('import_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('entity', 30);
                $table->string('source', 30)->default('file');
                $table->string('file_name', 190)->nullable();
                $table->text('summary')->nullable();
                $table->longText('created_ids');
                $table->string('status', 12)->default('active');
                $table->timestamp('undone_at')->nullable();
                $table->unsignedBigInteger('undone_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('data_retention_rules')) {
            Schema::create('data_retention_rules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->string('entity', 40);
                $table->unsignedSmallInteger('keep_days');
                $table->boolean('archive')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_run_at')->nullable();
                $table->unsignedInteger('last_removed')->default(0);
                $table->timestamps();
                $table->unique(['company_id', 'entity']);
            });
        }

        if (! Schema::hasTable('company_groups')) {
            Schema::create('company_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->unsignedBigInteger('owner_user_id');
                $table->timestamps();
            });

            Schema::create('company_group_members', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_group_id')->index();
                $table->unsignedBigInteger('company_id')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('help_articles')) {
            Schema::create('help_articles', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 160);
                $table->string('slug', 190)->unique();
                $table->string('category', 60)->default('general');
                $table->longText('body');
                $table->boolean('is_published')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('company_group_members');
        Schema::dropIfExists('company_groups');
        Schema::dropIfExists('data_retention_rules');
        Schema::dropIfExists('import_runs');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('business_settings');

        if (Schema::hasColumn('branches', 'parent_id')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->dropColumn('parent_id');
            });
        }
    }
};
