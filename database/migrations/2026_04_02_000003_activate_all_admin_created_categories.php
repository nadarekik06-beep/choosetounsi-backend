<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: subcategories created via admin panel were saved with is_active = 0
 * because Laravel's boolean validation received a JSON boolean `true` from
 * the frontend but stored it as 0 in MySQL's TINYINT column.
 *
 * This activates ALL subcategories that have is_active = 0.
 * The admin can deactivate individual ones manually via the toggle button.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Activate every subcategory that was incorrectly saved as inactive
        DB::table('subcategories')
            ->where('is_active', false)
            ->update(['is_active' => true]);

        // Same fix for categories
        DB::table('categories')
            ->where('is_active', false)
            ->update(['is_active' => true]);
    }

    public function down(): void
    {
        // Not reversible
    }
};