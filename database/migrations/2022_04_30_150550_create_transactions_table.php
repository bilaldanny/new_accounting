<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->nullable()->unsigned()->index();
            $table->bigInteger('branch_id')->nullable()->unsigned()->index();
            $table->bigInteger('tobranch_id')->nullable()->unsigned()->index();
            $table->bigInteger('contact_id')->nullable()->unsigned()->index();
            $table->bigInteger('opening_stock_product_id')->nullable()->unsigned()->index();
            $table->bigInteger('parent_id')->nullable()->unsigned()->index();
            $table->bigInteger('tax_id')->nullable()->unsigned()->index();
            $table->bigInteger('transporter_id')->nullable()->unsigned()->index();
            $table->bigInteger('created_by')->nullable()->unsigned()->index();
            $table->bigInteger('approved_by')->nullable()->unsigned()->index();
            $table->bigInteger('direct_contact_id')->nullable()->unsigned()->index();
            $table->Integer('total_item')->nullable();
            $table->string('pay_term')->nullable();
            $table->String('invoice_no')->nullable();
            $table->String('shipping_details')->nullable();
            $table->String('delivered_to')->nullable();
            $table->text('billty_no')->nullable();
            $table->text('billty_image')->nullable();
            $table->text('shipping_address')->nullable();
            $table->text('additional_note')->nullable();
            $table->text('packing')->nullable();
            $table->text('attachment')->nullable();
            $table->DateTime('transaction_date')->nullable();
            $table->Date('approved_date')->nullable();
            $table->enum('pay_type', ['month', 'day', 'year'])->default('day');
            $table->enum('status', ['received', 'pending', 'ordered', 'draft', 'final', 'issue', 'approved', 'in_transit', 'completed', 'quotation'])->default('pending');
            $table->enum('payment_status', ['paid', 'due', 'partial'])->default('due');
            $table->enum('adjustment_type', ['normal', 'abnormal', 'unboxing', 'opening'])->default('normal');
            $table->enum('discount_type', ['fixed', 'percentage', 'none'])->default('none');
            $table->enum('type', ['opening_stock', 'purchaseorder', 'sell', 'issue_note', 'adjustment', 'transfer', 'purchasereturn', 'recieving_note', 'salereturn'])->nullable();
            $table->enum('shipping_status', ['ordered', 'packed', 'shipped', 'delivered', 'cancelled'])->nullable();
            $table->double('total_before_tax', 9, 2)->nullable();
            $table->double('tax_amount', 9, 2)->nullable();
            $table->double('discount_amount', 9, 2)->nullable();
            $table->double('shipping_charges', 9, 2)->nullable();
            $table->double('final_amount', 9, 2)->nullable();
            $table->boolean('link_account')->default(0);
            $table->boolean('is_direct')->default(0);
            $table->boolean('is_print')->default(0);
            $table->boolean('is_edit')->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('tobranch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('opening_stock_product_id')->references('id')->on('products')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('transactions')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('tax_id')->references('id')->on('taxes')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('direct_contact_id')->references('id')->on('contacts')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('transactions');
    }
}
