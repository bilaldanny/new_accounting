<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a sale name the sales commission agent it belongs to, so commission can be tracked per sale later.
 * Nullable and optional: nothing calculates a commission from it yet, and a sale without an agent (every
 * existing one) is unchanged. Deleting an agent for good clears the link instead of blocking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('commission_agent_id')->nullable()->after('transporter_id')->index();

            $table->foreign('commission_agent_id')->references('id')->on('commission_agents')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['commission_agent_id']);
            $table->dropColumn('commission_agent_id');
        });
    }
};
