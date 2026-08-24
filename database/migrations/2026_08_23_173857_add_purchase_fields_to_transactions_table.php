<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('sup_ref_no')->nullable()->after('invoice_no')->index();
            $table->text('shipping_note')->nullable()->after('shipping_details');
            $table->double('paid_amount', 9, 2)->nullable()->after('final_amount');
            $table->unsignedBigInteger('updated_by')->nullable()->index()->after('created_by');
            $table->softDeletes();

            $table->foreign('updated_by')->references('id')->on('users')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['updated_by']);
            $table->dropColumn([
                'sup_ref_no',
                'shipping_note',
                'paid_amount',
                'updated_by',
                'deleted_at',
            ]);
        });
    }
};
