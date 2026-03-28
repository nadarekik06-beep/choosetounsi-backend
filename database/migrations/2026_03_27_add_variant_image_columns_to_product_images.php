<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend product_images to support variant-linked images.
 *
 * variant_id  — links this image to a specific variant (e.g. the Red/M row)
 * color_option_id — links by COLOR attribute option only (for galleries grouped by color)
 *
 * Priority at render time:
 *   1. variant_id match (most specific)
 *   2. color_option_id match (color group fallback)
 *   3. is_primary = true (product-level fallback)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            if (!Schema::hasColumn('product_images', 'variant_id')) {
                $table->foreignId('variant_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('product_variants')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('product_images', 'color_option_id')) {
                $table->foreignId('color_option_id')
                    ->nullable()
                    ->after('variant_id')
                    ->constrained('attribute_options')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            if (Schema::hasColumn('product_images', 'color_option_id')) {
                $table->dropForeign(['color_option_id']);
                $table->dropColumn('color_option_id');
            }
            if (Schema::hasColumn('product_images', 'variant_id')) {
                $table->dropForeign(['variant_id']);
                $table->dropColumn('variant_id');
            }
        });
    }
};