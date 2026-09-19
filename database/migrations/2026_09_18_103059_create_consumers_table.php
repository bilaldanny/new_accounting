<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('consumers', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->nullable()->unsigned()->index();
            $table->bigInteger('branch_id')->nullable()->unsigned()->index();
            $table->bigInteger('account_id')->nullable()->unsigned()->index();
            $table->bigInteger('country_id')->nullable()->unsigned()->index();
            $table->string('city')->nullable();
            $table->string('name');
            $table->text('address')->nullable();
            $table->text('store_address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone_res')->nullable();
            $table->string('phone_off')->nullable();
            $table->string('fax_no')->nullable();
            $table->string('email')->nullable();
            $table->string('ntn_no')->nullable();
            $table->string('cnic_no')->nullable();
            $table->string('sales_tax_no')->nullable();
            $table->string('consumer_type')->default('individual');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consumers');
    }
};
