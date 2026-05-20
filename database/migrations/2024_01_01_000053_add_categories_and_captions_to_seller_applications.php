<?php
// database/migrations/2024_01_01_000002_add_categories_and_captions_to_seller_applications.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * business_categories: JSON array of category names selected from the DB.
     *   - business_category (existing) is kept and auto-populated with categories[0]
     *     so old admin views and API consumers are never broken.
     *
     * sample_captions: JSON array, same index as sample_images.
     *   - null-safe: existing rows have sample_captions = null → frontend falls back to no caption.
     */
    public function up(): void
    {
        Schema::table('seller_applications', function (Blueprint $table) {
            $table->json('business_categories')
                  ->nullable()
                  ->after('business_category')
                  ->comment('Multi-select category names from DB');

            $table->json('sample_captions')
                  ->nullable()
                  ->after('sample_images')
                  ->comment('Per-image captions, index-matched to sample_images[]');
        });
    }

    public function down(): void
    {
        Schema::table('seller_applications', function (Blueprint $table) {
            $table->dropColumn(['business_categories', 'sample_captions']);
        });
    }
};