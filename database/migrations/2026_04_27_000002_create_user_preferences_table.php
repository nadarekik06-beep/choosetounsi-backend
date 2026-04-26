<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores user shopping preferences collected during onboarding.
 *
 * Design decisions:
 *   - category_ids   : JSON array of category IDs the user selected
 *   - brand_ids      : JSON array of attribute_option IDs for "brand" attribute
 *   - gender         : nullable string ('male', 'female', 'unisex') — optional
 *   - price_min/max  : nullable decimals for preferred price range
 *
 * Kept as a separate table (not on users) to keep users table clean
 * and to allow future expansion (more preference fields) without schema churn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->unique()
                  ->constrained('users')
                  ->onDelete('cascade');

            // Gender preference (optional)
            $table->enum('gender', ['male', 'female', 'unisex'])
                  ->nullable()
                  ->comment('Optional gender preference for product filtering');

            // Preferred categories — array of category IDs
            // e.g. [1, 3, 7]
            $table->json('category_ids')
                  ->nullable()
                  ->comment('Array of preferred category IDs');

            // Preferred brands — array of attribute_option IDs for brand attribute
            // e.g. [12, 45] — these are attribute_options.id values where attribute.slug = brand
            $table->json('brand_ids')
                  ->nullable()
                  ->comment('Array of preferred brand attribute_option IDs');

            // Price range (optional)
            $table->decimal('price_min', 10, 3)
                  ->nullable()
                  ->comment('Minimum preferred price in TND');

            $table->decimal('price_max', 10, 3)
                  ->nullable()
                  ->comment('Maximum preferred price in TND');

            $table->timestamps();

            // user_id is unique (one preference record per user), index already on FK
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};