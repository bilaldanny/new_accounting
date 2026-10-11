<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files kept on a customer or supplier (agreements, tax certificates, bank letters). The file itself sits on the private
 * disk and is served only through the authenticated download route; `size` is what the company's storage limit counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contact_attachments')) {
            return;
        }

        Schema::create('contact_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('contact_id')->index();
            $table->string('file_name');
            $table->string('path');
            $table->string('ext', 10);
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_attachments');
    }
};
