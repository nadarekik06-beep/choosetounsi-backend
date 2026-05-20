<?php
// database/migrations/2024_01_01_000001_create_delivery_company_profiles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDeliveryCompanyProfilesTable extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_company_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('company_name', 150);
            $table->string('phone', 20);
            $table->string('address', 255);
            $table->string('wilaya', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('registration_number', 50)->nullable(); // RC / SIRET
            $table->string('logo_url', 500)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_company_profiles');
    }
}