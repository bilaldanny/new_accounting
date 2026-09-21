<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `loyalty_settings`: one row per company, off until an admin switches it on. `loyalty_point_entries`:
     * the points ledger of a customer (`points` is signed: earn > 0, redeem < 0, adjust either way) with the
     * balance after each line. A customer's balance is the sum of their entries.
     */
    public function up(): void
    {
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->unsigned()->unique();
            $table->boolean('is_enabled')->default(false);
            $table->double('amount_per_point', 9, 2)->default(100);
            $table->double('point_value', 9, 2)->default(1);
            $table->unsignedInteger('min_redeem_points')->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
        });

        Schema::create('loyalty_point_entries', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->unsigned()->index();
            $table->bigInteger('contact_id')->unsigned()->index();
            $table->enum('type', ['earn', 'redeem', 'adjust']);
            $table->integer('points');
            $table->integer('balance_after');
            $table->double('amount', 9, 2)->nullable();
            $table->bigInteger('transaction_id')->nullable()->unsigned()->index();
            $table->bigInteger('user_id')->nullable()->unsigned()->index();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_entries');
        Schema::dropIfExists('loyalty_settings');
    }
};
