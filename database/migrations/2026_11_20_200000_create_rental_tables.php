<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 8, rental: rentable items (a product variation with a number of units, a daily rate, a deposit and a late fee per day),
 * bookings on a calendar that cannot overbook an item, the deposit taken at pickup and the return with its inspection, late charge
 * and damage charge. The ledger moves only when a company switches rental posting on and maps its accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_items')) {
            Schema::create('rental_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('variation_id');
                $table->unsignedInteger('units')->default(1);
                $table->decimal('daily_rate', 15, 2)->default(0);
                $table->decimal('deposit_amount', 15, 2)->default(0);
                $table->decimal('late_fee_per_day', 15, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('notes', 200)->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'product_id', 'variation_id']);
            });
        }

        if (! Schema::hasTable('rental_bookings')) {
            Schema::create('rental_bookings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('booking_no', 30);
                $table->unsignedBigInteger('rental_item_id')->index();
                $table->unsignedBigInteger('contact_id')->index();
                $table->unsignedInteger('quantity')->default(1);
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status', 10)->default('reserved')->index();
                $table->decimal('daily_rate', 15, 2)->default(0);
                $table->decimal('deposit_amount', 15, 2)->default(0);
                $table->decimal('late_fee_per_day', 15, 2)->default(0);
                $table->decimal('rent_amount', 15, 2)->default(0);
                $table->decimal('deposit_received', 15, 2)->default(0);
                $table->dateTime('picked_up_at')->nullable();
                $table->date('returned_on')->nullable();
                $table->unsignedInteger('late_days')->default(0);
                $table->decimal('late_charge', 15, 2)->default(0);
                $table->decimal('damage_charge', 15, 2)->default(0);
                $table->string('condition', 14)->nullable();
                $table->string('inspection_notes', 500)->nullable();
                $table->decimal('deposit_applied', 15, 2)->default(0);
                $table->decimal('deposit_refund', 15, 2)->default(0);
                $table->decimal('amount_due', 15, 2)->default(0);
                $table->unsignedBigInteger('pickup_voucher_id')->nullable();
                $table->unsignedBigInteger('return_voucher_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'booking_no']);
            });
        }

        if (! Schema::hasTable('rental_settings')) {
            Schema::create('rental_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->text('settings');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['rental_settings', 'rental_bookings', 'rental_items'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
