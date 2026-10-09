<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usage-based/metered billing: a simple append-only counter per billing period. "Metered billing" means
 * different things per business, so this build takes the simplest common shape — a per-unit quantity
 * recorded against a period, billed as (quantity - plan.included_units) * plan.overage_rate when
 * positive — flagged in the audit doc as a reasonable default, not the only possible interpretation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_subscription_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_subscription_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('quantity', 12, 2);
            $table->string('note')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_subscription_usages');
    }
};
