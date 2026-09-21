<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per company and settings group (`barcode`, `invoice`, `receipt`): the values are a JSON
     * object whose keys and rules are defined in App\Models\DocumentSetting, so a group can gain a
     * setting without a migration. A company with no row uses the defaults.
     */
    public function up(): void
    {
        Schema::create('document_settings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('company_id')->unsigned();
            $table->string('group', 30);
            $table->json('settings');
            $table->timestamps();

            $table->unique(['company_id', 'group']);
            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_settings');
    }
};
