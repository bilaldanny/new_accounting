<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_holidays')) {
            Schema::create('company_holidays', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->date('holiday_date');
                $table->string('name', 120);
                $table->string('kind', 10)->default('company');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'holiday_date', 'name']);
            });
        }

        MenuMigration::install([
            ['sibling:/hrm/attendance', 'Holidays', '/hrm/holidays', 'hrm.holidays', 'Users', false],
        ]);
    }

    public function down(): void
    {
        MenuMigration::remove(['/hrm/holidays']);
        Schema::dropIfExists('company_holidays');
    }
};
