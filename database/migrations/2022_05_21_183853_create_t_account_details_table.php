<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTAccountDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('t_account_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('t_account_id')->nullable()->unsigned()->index();
            $table->bigInteger('branch_id')->nullable()->unsigned()->index();
            $table->bigInteger('coa_id')->nullable()->unsigned()->index();
            $table->bigInteger('contact_id')->nullable()->unsigned()->index();
            $table->string('account_code');
            $table->text('description')->nullable();
            $table->enum('acc_nature', ['cr', 'dr'])->default('cr');
            $table->double('credit', 9, 2)->nullable();
            $table->double('debit', 9, 2)->nullable();
            $table->boolean('highlight')->default(0);
            $table->double('amount', 9, 2)->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('coa_id')->references('id')->on('chart_of_accounts')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('t_account_id')->references('id')->on('t_accounts')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('t_account_details');
    }
}
