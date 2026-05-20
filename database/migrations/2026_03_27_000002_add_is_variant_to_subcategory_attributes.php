<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add is_variant column to subcategory_attributes pivot.
 *
 * When is_variant = true  → this attribute generates variant combinations
 *                           (e.g. Color + Size → Red/S, Red/M, Black/S …)
 * When is_variant = false → informational / filter attribute only
 *                           (e.g. Material, Brand, Condition)
 *
 * This is configured PER SUBCATEGORY so that:
 *   - Dresses: Color=variant, Size=variant, Material=info
 *   - Laptops:  RAM=variant, Storage=variant, Brand=info
 *   - Phones:   Storage=variant, Color=variant, OS=info
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcategory_attributes', function (Blueprint $table) {
            // Default false so existing rows are non-breaking
            $table->boolean('is_variant')->default(false)->after('is_required');
        });
    }

    public function down(): void
    {
        Schema::table('subcategory_attributes', function (Blueprint $table) {
            $table->dropColumn('is_variant');
        });
    }
};