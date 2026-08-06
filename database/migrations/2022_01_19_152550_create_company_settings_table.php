<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompanySettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->nullable()->unsigned()->index();
            $table->string('business_name')->nullable();
            $table->string('start_date')->nullable();
            $table->string('currency_placement')->nullable();
            $table->bigInteger('currency_id')->nullable()->unsigned()->index();
            $table->double('profit_percent', 9, 2)->nullable();
            $table->string('logo')->nullable();
            $table->bigInteger('timezone_id')->nullable()->unsigned()->index();
            $table->string('financial_start_month')->nullable();
            $table->string('date_format')->nullable();
            $table->string('time_format')->nullable();
            $table->string('transaction_edit_days')->nullable();
            $table->string('purchase_order')->nullable();
            $table->string('purchase_return')->nullable();
            $table->string('stock_transfer')->nullable();
            $table->string('stock_adjustment')->nullable();
            $table->string('sell_return')->nullable();
            $table->string('invoice')->nullable();
            $table->string('expenses')->nullable();
            $table->string('supplier')->nullable();
            $table->string('customer')->nullable();
            $table->string('bank')->nullable();
            $table->string('product')->nullable();
            $table->string('purchase_payment')->nullable();
            $table->string('sell_payment')->nullable();
            $table->string('expense_payment')->nullable();
            $table->string('business_location')->nullable();
            $table->string('subscription_no')->nullable();
            $table->string('draft')->nullable();
            $table->string('opening_stock')->nullable();
            $table->string('grn')->nullable();
            $table->string('gin')->nullable();
            $table->boolean('purchase_approval')->default(0);
            $table->boolean('sell_approval')->default(0);
            $table->boolean('journal_entry')->default(0);
            $table->boolean('show_sku')->default(0);
            $table->boolean('cash_collection')->default(0);
            $table->boolean('payment')->default(0);
            $table->boolean('limit_account')->default(0);
            $table->boolean('auto_grn')->default(0);
            $table->boolean('auto_gin')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->engine = 'InnoDB';

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('currency_id')->references('id')->on('currencies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('timezone_id')->references('id')->on('timezones')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('company_settings');
    }
}
