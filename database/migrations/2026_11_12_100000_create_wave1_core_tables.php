<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 1 (small core modules): in-app notifications (shared by every later wave), CRM lead allocation and follow-up reminders,
 * customer subscription reminders, and the security tables of User Management (IP rules, login events, tracked sessions, security
 * settings with the password policy and maker-checker switch, password history, privacy requests), plus per-user dashboard layout.
 * Every table is additive and nothing changes behaviour until a company configures it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_notifications')) {
            Schema::create('app_notifications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('user_id');
                $table->string('kind', 40);
                $table->string('title', 160);
                $table->text('body')->nullable();
                $table->string('url', 255)->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'read_at']);
            });
        }

        if (! Schema::hasTable('lead_allocation_rules')) {
            Schema::create('lead_allocation_rules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 120);
                $table->string('strategy', 15);
                $table->unsignedBigInteger('lead_source_id')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->json('user_ids');
                $table->unsignedInteger('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('cursor')->default(0);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('activities') && ! Schema::hasColumn('activities', 'reminded_at')) {
            Schema::table('activities', function (Blueprint $table): void {
                $table->timestamp('reminded_at')->nullable();
            });
        }

        if (! Schema::hasTable('subscription_reminders')) {
            Schema::create('subscription_reminders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('customer_subscription_invoice_id');
                $table->string('kind', 20);
                $table->string('channel', 10);
                $table->string('status', 14);
                $table->text('message')->nullable();
                $table->timestamps();
                $table->unique(['customer_subscription_invoice_id', 'kind'], 'subscription_reminders_unique');
            });
        }

        if (! Schema::hasTable('ip_rules')) {
            Schema::create('ip_rules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('type', 5);
                $table->string('address', 64);
                $table->string('note', 190)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('login_events')) {
            Schema::create('login_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('identity', 190)->nullable();
                $table->string('event', 14)->index();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->string('device', 120)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('user_sessions')) {
            Schema::create('user_sessions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('session_hash', 64)->unique();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->string('device', 120)->nullable();
                $table->timestamp('last_activity')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('security_settings')) {
            Schema::create('security_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->boolean('maker_checker')->default(false);
                $table->boolean('policy_enabled')->default(false);
                $table->unsignedSmallInteger('min_length')->default(8);
                $table->boolean('require_number')->default(false);
                $table->boolean('require_mixed_case')->default(false);
                $table->boolean('require_symbol')->default(false);
                $table->unsignedSmallInteger('expiry_days')->default(0);
                $table->unsignedSmallInteger('history_count')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('password_histories')) {
            Schema::create('password_histories', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('hash', 255);
                $table->timestamp('created_at')->nullable();
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('password_changed_at')->nullable();
            });
        }

        if (! Schema::hasTable('privacy_requests')) {
            Schema::create('privacy_requests', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('subject_type', 10);
                $table->unsignedBigInteger('subject_id');
                $table->string('kind', 10);
                $table->string('status', 10)->default('done');
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('dashboard_preferences')) {
            Schema::create('dashboard_preferences', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->json('layout');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['dashboard_preferences', 'privacy_requests', 'password_histories', 'security_settings', 'user_sessions', 'login_events', 'ip_rules', 'subscription_reminders', 'lead_allocation_rules', 'app_notifications'] as $table) {
            Schema::dropIfExists($table);
        }

        foreach ([['users', 'password_changed_at'], ['activities', 'reminded_at']] as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropColumn($column);
                });
            }
        }
    }
};
