<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompaniesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('ntn_no')->nullable()->index();
            $table->string('strn_no')->nullable()->index();
            $table->string('gst_no')->nullable()->index();
            $table->string('registration_no')->nullable()->index();
            $table->text('address')->nullable();
            $table->bigInteger('country_id')->nullable()->unsigned()->index();
            $table->bigInteger('state_id')->nullable()->unsigned()->index();
            $table->bigInteger('city_id')->nullable()->unsigned()->index();
            $table->string('zipcode')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('max_users')->default(10);
            $table->integer('max_branches')->default(2);
            $table->timestamps();
            $table->softDeletes();
            $table->engine = 'InnoDB';
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('companies');
    }
}
