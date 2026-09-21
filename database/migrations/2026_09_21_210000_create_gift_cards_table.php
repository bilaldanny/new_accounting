<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A gift card / voucher (`gift_cards`) and its ledger (`gift_card_entries`): one `issue` entry when the
     * card is created, then a `redeem` or `topup` entry per movement, each recording the balance after it.
     * `balance` on the card is always the last entry's `balance_after`. The code is unique per company
     * (checked in the controller, trashed cards included, so restoring one can never clash).
     */
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->nullable()->unsigned()->index();
            $table->string('code', 50)->index();
            $table->bigInteger('contact_id')->nullable()->unsigned()->index();
            $table->double('initial_value', 9, 2)->default(0);
            $table->double('balance', 9, 2)->default(0);
            $table->date('expires_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onUpdate('cascade')->onDelete('set null');
        });

        Schema::create('gift_card_entries', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('gift_card_id')->unsigned()->index();
            $table->enum('type', ['issue', 'redeem', 'topup']);
            $table->double('amount', 9, 2);
            $table->double('balance_after', 9, 2);
            $table->bigInteger('transaction_id')->nullable()->unsigned()->index();
            $table->bigInteger('user_id')->nullable()->unsigned()->index();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->foreign('gift_card_id')->references('id')->on('gift_cards')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gift_card_entries');
        Schema::dropIfExists('gift_cards');
    }
};
