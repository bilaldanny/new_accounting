<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTAccountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('t_accounts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->nullable()->unsigned()->index();
            $table->bigInteger('branch_id')->nullable()->unsigned()->index();
            $table->bigInteger('coa_id')->nullable()->unsigned()->index();
            $table->bigInteger('received_id')->nullable()->unsigned()->index();
            $table->bigInteger('created_by')->nullable()->unsigned()->index();
            $table->bigInteger('approved_by')->nullable()->unsigned()->index();
            $table->bigInteger('cancelled_by')->nullable()->unsigned()->index();
            $table->bigInteger('printed_by')->nullable()->unsigned()->index();
            $table->bigInteger('issuer_id')->nullable()->unsigned()->index();
            $table->string('account_code');
            $table->string('voucher_no');
            $table->string('ref_no');
            $table->string('cheque_no');
            $table->Date('cheque_post_date')->nullable();
            $table->Date('voucher_date')->nullable();
            $table->double('total_amount', 9, 2)->nullable();
            $table->double('total_tax', 9, 2)->nullable();
            $table->double('net_total', 9, 2)->nullable();
            $table->boolean('is_print')->default(0);
            $table->text('comments');
            $table->enum('status', ['pending', 'approved', 'cancelled'])->default('pending');
            $table->enum('type', ['online', 'bank', 'cash'])->default('online');
            $table->DateTime('printed_at')->nullable();
            $table->DateTime('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('coa_id')->references('id')->on('chart_of_accounts')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('cancelled_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('printed_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('t_accounts');
    }
}
