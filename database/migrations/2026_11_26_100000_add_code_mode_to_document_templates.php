<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_templates') && ! Schema::hasColumn('document_templates', 'mode')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                $table->string('mode', 10)->default('blocks');
                $table->mediumText('code_html')->nullable();
                $table->mediumText('code_css')->nullable();
            });
        }

        if (! Schema::hasTable('document_template_versions')) {
            Schema::create('document_template_versions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('template_id')->index();
                $table->mediumText('code_html')->nullable();
                $table->mediumText('code_css')->nullable();
                $table->unsignedBigInteger('saved_by')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_template_versions');

        if (Schema::hasColumn('document_templates', 'mode')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                $table->dropColumn(['mode', 'code_html', 'code_css']);
            });
        }
    }
};
