<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax exemption rules: a customer (or supplier) or an item taken out of one tax or of every tax, for a period. A sale or
 * purchase line keeps the rule that exempted it (`tax_exemption_id`) so the exempt sales can be listed later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_exemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('scope', 20);
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->restrictOnDelete();
            $table->string('certificate_no')->nullable();
            $table->string('reason')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        foreach (['sell_lines', 'purchase_lines'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('tax_exemption_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['sell_lines', 'purchase_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_tax_exemption_id_index');
                $table->dropColumn('tax_exemption_id');
            });
        }

        Schema::dropIfExists('tax_exemptions');
    }
};
