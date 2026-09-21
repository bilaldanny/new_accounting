<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A company's exchange rates, for display only: how many units of `currency_id` one unit of the company's
     * base currency (company_settings.currency_id) is worth. Sales and purchases carry no currency and all
     * accounting stays in the base currency; a rate only lets an invoice also show its total in the customer's
     * currency. One rate per company and currency.
     */
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->unsigned();
            $table->bigInteger('currency_id')->unsigned();
            $table->double('rate', 16, 6);
            $table->timestamps();

            $table->unique(['company_id', 'currency_id']);
            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('currency_id')->references('id')->on('currencies')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
