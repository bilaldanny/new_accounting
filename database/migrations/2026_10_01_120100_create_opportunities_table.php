<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->nullable()->unsigned()->index();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('name');
            $table->decimal('deal_value', 15, 2)->default(0);
            $table->date('expected_closing_date')->nullable();
            $table->unsignedBigInteger('pipeline_stage_id')->nullable()->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->string('status', 10)->default('open');
            $table->string('lost_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('contact_id')->references('id')->on('contacts')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('pipeline_stage_id')->references('id')->on('pipeline_stages')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('assigned_to')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
