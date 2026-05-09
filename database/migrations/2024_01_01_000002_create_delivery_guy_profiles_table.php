<?php
// database/migrations/2024_01_01_000002_create_delivery_guy_profiles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDeliveryGuyProfilesTable extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_guy_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('wilaya', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->enum('vehicle_type', ['moto', 'car', 'van', 'bicycle', 'on_foot'])->default('moto');
            $table->string('vehicle_plate', 20)->nullable();
            $table->string('id_card_number', 50)->nullable(); // CIN
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_guy_profiles');
    }
}