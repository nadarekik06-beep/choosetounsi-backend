<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store voice for the AI description generator (Black Pepper): a short style
 * guide and keywords applied to every description the seller generates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_ai_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('brand_voice', 600)->nullable();
            $table->json('brand_keywords')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_ai_profiles');
    }
};
