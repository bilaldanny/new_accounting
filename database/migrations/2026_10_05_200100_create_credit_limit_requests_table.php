<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval Center (Phase 1, easy half) — Credit Limit: no credit-limit change request/approval concept
 * existed anywhere in the codebase, so this is a small new table rather than reuse of an existing one.
 * A request holds the contact's current and requested credit_limit; approving it applies the new limit
 * to `contacts.credit_limit` (see Contact::applyCreditLimitRequest()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_limit_requests', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->unsigned()->index();
            $table->bigInteger('branch_id')->nullable()->unsigned()->index();
            $table->bigInteger('contact_id')->unsigned()->index();
            $table->decimal('current_limit', 15, 2)->default(0);
            $table->decimal('requested_limit', 15, 2);
            $table->string('reason', 500)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->bigInteger('requested_by')->nullable()->unsigned()->index();
            $table->bigInteger('approved_by')->nullable()->unsigned()->index();
            $table->timestamp('approved_at')->nullable();
            $table->bigInteger('rejected_by')->nullable()->unsigned()->index();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('contact_id')->references('id')->on('contacts')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_limit_requests');
    }
};
