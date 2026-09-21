<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A discount rule: a percentage or a fixed amount off a sale, optionally behind a coupon code, a
     * minimum purchase, a cap and a validity window. `code` is unique per company among the live rows
     * (checked in the controller, not by an index, so a trashed row does not block its code).
     */
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->nullable()->unsigned()->index();
            $table->string('name');
            $table->string('code', 50)->nullable()->index();
            $table->enum('discount_type', ['percentage', 'fixed'])->default('percentage');
            $table->double('value', 9, 2)->default(0);
            $table->double('min_purchase_amount', 9, 2)->default(0);
            $table->double('max_discount_amount', 9, 2)->nullable();
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
