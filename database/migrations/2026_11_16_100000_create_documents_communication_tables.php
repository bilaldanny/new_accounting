<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 5, documents and communication: print templates (block-based design as sanitised JSON, by branch and document type, with a
 * default), message templates for email / SMS / WhatsApp, due and overdue reminder rules with their send log, attachments on sales and
 * purchase documents, custom fields (definitions and values, shared with the settings wave) and the company's instant-delivery setting.
 * Additive; no existing table changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_templates')) {
            Schema::create('document_templates', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('doc_type', 30);
                $table->string('language', 5)->default('en');
                $table->string('name', 120);
                $table->string('paper', 12)->default('a4');
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->longText('blocks');
                $table->text('branding')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('message_templates')) {
            Schema::create('message_templates', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('event', 30);
                $table->string('channel', 12);
                $table->string('language', 5)->default('en');
                $table->string('name', 120);
                $table->string('subject', 200)->nullable();
                $table->text('body');
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('document_reminder_rules')) {
            Schema::create('document_reminder_rules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 120);
                $table->string('trigger', 12);
                $table->unsignedSmallInteger('days')->default(0);
                $table->unsignedSmallInteger('repeat_every_days')->nullable();
                $table->unsignedTinyInteger('max_sends')->default(1);
                $table->string('channel', 12);
                $table->string('language', 5)->default('en');
                $table->unsignedBigInteger('message_template_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_run_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('document_reminder_log')) {
            Schema::create('document_reminder_log', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('rule_id')->index();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->date('sent_on');
                $table->string('channel', 12);
                $table->string('recipient', 190)->nullable();
                $table->string('status', 20);
                $table->string('error', 255)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['rule_id', 'transaction_id', 'sent_on']);
            });
        }

        if (! Schema::hasTable('document_attachments')) {
            Schema::create('document_attachments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->string('file_name', 190);
                $table->string('path', 255);
                $table->string('ext', 10);
                $table->string('mime', 100)->nullable();
                $table->unsignedInteger('size')->default(0);
                $table->string('description', 255)->nullable();
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('custom_field_definitions')) {
            Schema::create('custom_field_definitions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('entity', 20);
                $table->string('field_key', 60);
                $table->string('label', 120);
                $table->string('type', 12)->default('text');
                $table->text('options')->nullable();
                $table->boolean('is_required')->default(false);
                $table->boolean('show_on_print')->default(true);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['company_id', 'entity', 'field_key']);
            });
        }

        if (! Schema::hasTable('custom_field_values')) {
            Schema::create('custom_field_values', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('entity', 20);
                $table->unsignedBigInteger('entity_id');
                $table->unsignedBigInteger('field_id');
                $table->text('value')->nullable();
                $table->timestamps();
                $table->unique(['entity', 'entity_id', 'field_id']);
            });
        }

        if (! Schema::hasTable('document_delivery_settings')) {
            Schema::create('document_delivery_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->boolean('auto_send_sale')->default(false);
                $table->string('channel', 12)->default('email');
                $table->string('language', 5)->default('en');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_delivery_settings');
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_definitions');
        Schema::dropIfExists('document_attachments');
        Schema::dropIfExists('document_reminder_log');
        Schema::dropIfExists('document_reminder_rules');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('document_templates');
    }
};
