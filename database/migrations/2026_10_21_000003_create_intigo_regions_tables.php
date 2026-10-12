<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intigo's place names (php artisan intigo:sync-regions, re-runnable upsert).
 * ids are Intigo's own. intigo_cities.wilaya = our canonical governorate name
 * (App\Support\Wilayas), so the existing wilaya columns keep matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intigo_cities', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 120);
            $table->string('name_ar', 120)->nullable();
            $table->string('wilaya', 100)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('intigo_districts', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('city_id');
            $table->string('name', 120);
            $table->string('name_ar', 120)->nullable();
            $table->timestamps();
            $table->index(['city_id', 'name']);
            $table->foreign('city_id')->references('id')->on('intigo_cities')->cascadeOnDelete();
        });

        Schema::create('intigo_neighborhoods', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('district_id');
            $table->string('name', 120);
            $table->string('name_ar', 120)->nullable();
            $table->timestamps();
            $table->index('district_id');
            $table->foreign('district_id')->references('id')->on('intigo_districts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intigo_neighborhoods');
        Schema::dropIfExists('intigo_districts');
        Schema::dropIfExists('intigo_cities');
    }
};
