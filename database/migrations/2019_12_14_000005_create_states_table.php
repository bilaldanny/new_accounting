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
        Schema::create('states', function (Blueprint $table) {
            $table->mediumIncrements('id');
            $table->string('name', 255);
            $table->mediumInteger('country_id')->unsigned()->index();
            $table->char('country_code', 2);
            $table->string('fips_code', 255)->nullable();
            $table->string('iso2', 255)->nullable();
            $table->string('type', 191)->nullable();
            $table->integer('level')->nullable();
            $table->integer('parent_id')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamps(); // created_at & updated_at
            $table->boolean('flag')->default(1);
            $table->string('wikiDataId', 255)->nullable();
            $table->softDeletes();
            $table->engine = 'InnoDB';

            // Foreign key constraint (if you want to link to countries table)
            $table->foreign('country_id')->references('id')->on('countries')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};
