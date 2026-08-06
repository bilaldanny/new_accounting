<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBanksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->nullable()->unsigned()->index();
            $table->bigInteger('branch_id')->nullable()->unsigned()->index();
            $table->string('first_name');
            $table->string('bank_name');
            $table->string('prefix')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('gl_id')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('alternate_no')->nullable();
            $table->string('landline')->nullable();
            $table->string('landmark')->nullable();
            $table->bigInteger('country_id')->nullable()->unsigned()->index();
            $table->bigInteger('state_id')->nullable()->unsigned()->index();
            $table->bigInteger('city_id')->nullable()->unsigned()->index();
            $table->boolean('active')->default(1);
            $table->boolean('link_account')->default(1);
            $table->text('address')->nullable();
            $table->string('code');
            $table->enum('type', ['export', 'local'])->default('local');

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('banks');
    }
}
