<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR e-invoicing structure (no live integration): a settings row per company (switch, environment, POS id and credentials -
 * the secrets are stored encrypted by the model and are empty) and one submission row per sale handed to the gateway. Also the
 * STRN of a customer or supplier (the NTN was already there), and the company's default for whether prices include tax.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fbr_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('environment', 20)->default('sandbox');
            $table->string('pos_id', 100)->nullable();
            $table->string('api_url')->nullable();
            $table->string('username', 150)->nullable();
            $table->text('password')->nullable();
            $table->text('api_token')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained('transactions')->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('fbr_invoice_number')->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('strn_number')->nullable()->after('ntn_number');
        });

        Schema::table('company_settings', function (Blueprint $table): void {
            $table->boolean('tax_inclusive_pricing')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn('tax_inclusive_pricing');
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('strn_number');
        });

        Schema::dropIfExists('fbr_submissions');
        Schema::dropIfExists('fbr_settings');
    }
};
