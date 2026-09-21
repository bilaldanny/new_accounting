<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A stock take (physical count) of one branch: `stock_takes` is the count sheet and
     * `stock_take_lines` its products, each with the system quantity frozen when the sheet was opened
     * and the quantity counted. Completing a sheet writes a normal completed stock adjustment
     * (`adjustment_id`) for the differences; nothing else touches stock.
     */
    public function up(): void
    {
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->unsigned()->index();
            $table->bigInteger('branch_id')->unsigned()->index();
            $table->string('reference', 50);
            $table->date('count_date');
            $table->enum('status', ['draft', 'completed'])->default('draft');
            $table->string('note', 500)->nullable();
            $table->bigInteger('adjustment_id')->nullable()->unsigned()->index();
            $table->timestamp('completed_at')->nullable();
            $table->bigInteger('created_by')->nullable()->unsigned();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('adjustment_id')->references('id')->on('transactions')->onUpdate('cascade')->onDelete('set null');
        });

        Schema::create('stock_take_lines', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('stock_take_id')->unsigned()->index();
            $table->bigInteger('product_id')->unsigned()->index();
            $table->bigInteger('variation_id')->unsigned()->index();
            $table->bigInteger('unit_id')->nullable()->unsigned();
            $table->double('system_qty', 15, 4)->default(0);
            $table->double('counted_qty', 15, 4)->nullable();
            $table->timestamps();

            $table->foreign('stock_take_id')->references('id')->on('stock_takes')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_take_lines');
        Schema::dropIfExists('stock_takes');
    }
};
