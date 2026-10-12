<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 2, Approval Center. A workflow is a rule (document type, amount range, branch, creator role) with ordered levels; a level
 * has approvers (users or roles) and a mode (any one, or all of them). A document that matches no active workflow keeps the
 * approval it has always had. Delegations let an approver be covered while away. Discount codes can be put behind an approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('approval_workflows')) {
            Schema::create('approval_workflows', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 120);
                $table->string('document_type', 30)->index();
                $table->decimal('min_amount', 15, 2)->nullable();
                $table->decimal('max_amount', 15, 2)->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('creator_role_id')->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->unsignedInteger('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            Schema::create('approval_workflow_levels', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('approval_workflow_id')->index();
                $table->unsignedSmallInteger('level_no');
                $table->string('label', 80);
                $table->string('mode', 5)->default('any');
                $table->json('approvers');
                $table->timestamps();
                $table->unique(['approval_workflow_id', 'level_no']);
            });
        }

        if (! Schema::hasTable('approval_requests')) {
            Schema::create('approval_requests', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('approval_workflow_id');
                $table->string('document_type', 30);
                $table->unsignedBigInteger('document_id');
                $table->string('document_no', 60)->nullable();
                $table->decimal('amount', 15, 2)->default(0);
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->string('status', 10)->default('pending')->index();
                $table->unsignedSmallInteger('current_level')->default(1);
                $table->unsignedSmallInteger('notified_level')->default(0);
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'document_type', 'document_id'], 'approval_requests_document_unique');
            });

            Schema::create('approval_actions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('approval_request_id')->index();
                $table->unsignedSmallInteger('level_no');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('on_behalf_of')->nullable();
                $table->string('action', 8);
                $table->string('comment', 500)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('approval_delegations')) {
            Schema::create('approval_delegations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('from_user_id')->index();
                $table->unsignedBigInteger('to_user_id')->index();
                $table->date('starts_on');
                $table->date('ends_on');
                $table->string('reason', 190)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('approval_settings')) {
            Schema::create('approval_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->boolean('discount_code_approval')->default(false);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('discounts') && ! Schema::hasColumn('discounts', 'approval_status')) {
            Schema::table('discounts', function (Blueprint $table): void {
                $table->string('approval_status', 10)->default('approved');
                $table->unsignedBigInteger('approved_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['approval_settings', 'approval_delegations', 'approval_actions', 'approval_requests', 'approval_workflow_levels', 'approval_workflows'] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasColumn('discounts', 'approval_status')) {
            Schema::table('discounts', function (Blueprint $table): void {
                $table->dropColumn(['approval_status', 'approved_by']);
            });
        }
    }
};
