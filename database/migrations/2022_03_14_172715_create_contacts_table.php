<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateContactsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->nullable()->unsigned()->index();
            $table->bigInteger('branch_id')->nullable()->unsigned()->index();
            $table->bigInteger('currency_id')->nullable()->unsigned()->index();
            $table->bigInteger('country_id')->nullable()->unsigned()->index();
            $table->bigInteger('state_id')->nullable()->unsigned()->index();
            $table->bigInteger('city_id')->nullable()->unsigned()->index();
            $table->Integer('link_id')->nullable();
            $table->string('prefix')->nullable();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('business_name');
            $table->string('gl_id')->nullable();
            $table->string('pay_term')->nullable();
            $table->enum('pay_type', ['month', 'day', 'year'])->default('day');
            $table->integer('credit_limit')->default(0);
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('alternate_no')->nullable();
            $table->string('landline')->nullable();
            $table->string('landmark')->nullable();
            $table->boolean('active')->default(1);
            $table->boolean('link_account')->default(1);
            $table->text('address');
            $table->string('code');
            $table->enum('user_type', ['customer', 'supplier', 'both'])->default('customer');
            $table->enum('type', ['export', 'local'])->default('local');
            $table->string('ntn_number');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('currency_id')->references('id')->on('currencies')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('contacts');
    }
}
