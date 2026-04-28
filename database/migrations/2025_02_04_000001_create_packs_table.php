<?php
// database/migrations/2025_xx_xx_000001_create_packs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('short_description', 500)->nullable();

            // Pack-level image (stored like product images)
            $table->string('image_path')->nullable();

            // Seller-defined custom price
            $table->decimal('pack_price', 10, 3);

            // Computed & cached at save time — sum of (item qty × effective variant price)
            $table->decimal('original_price', 10, 3)->default(0)
                ->comment('Sum of all item prices at full retail — for savings display');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_approved')->default(false);

            $table->integer('views')->default(0);
            $table->timestamps();

            $table->index(['seller_id', 'is_active', 'is_approved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packs');
    }
};