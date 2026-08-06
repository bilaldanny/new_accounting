<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChartOfAccountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->nullable()->unsigned()->index();
            $table->bigInteger('branch_id')->nullable()->unsigned()->index();
            $table->bigInteger('parent_id')->nullable()->unsigned()->index();
            $table->string('code')->nullable();
            $table->string('name');
            $table->enum('acc_type', ['t', 'c'])->default('t');
            $table->enum('acc_nature', ['cr', 'dr'])->default('cr');
            $table->boolean('pl')->default(0);
            $table->boolean('bs')->default(0);
            $table->boolean('active')->default(1);
            $table->text('branches')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('chart_of_accounts')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('chart_of_accounts');
    }
}
