<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An internal request for products before a Purchase Order exists: staff ask for what they need,
 * a manager/companyadmin approves it, and only then can it be converted into a real Purchase Order
 * (supplier and price are picked at that point, never here). This is a standalone table — a
 * requisition has no accounting effect and is never a `t_accounts` voucher or a `transactions` row
 * until it is converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requisition_no', 100);
            $table->date('requisition_date');
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'converted'])->default('pending');
            $table->text('note')->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisitions');
    }
};
