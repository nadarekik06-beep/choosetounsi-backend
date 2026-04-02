<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix categories and subcategories that were created via the admin panel
 * with is_active = 0 (false) instead of the intended is_active = 1 (true).
 *
 * Root cause: AdminCategoryController@store used $validated directly in
 * Category::create(). When the frontend sent is_active as a JSON boolean,
 * Laravel's 'sometimes|boolean' rule passed it — but if it was missing from
 * the payload for any reason, the DB column defaulted to whatever MySQL chose.
 *
 * This migration activates ALL categories and subcategories that have no
 * products attached yet (i.e. newly created ones that were never visible).
 * Categories with products are left untouched — if they were inactive
 * intentionally, we should not change them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Activate categories that have is_active = 0 AND no products
        // (these are newly created admin categories that should be active)
        $inactiveCategoryIds = DB::table('categories')
            ->where('is_active', false)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('products')
                  ->whereColumn('products.category_id', 'categories.id');
            })
            ->pluck('id');

        if ($inactiveCategoryIds->isNotEmpty()) {
            DB::table('categories')
                ->whereIn('id', $inactiveCategoryIds)
                ->update(['is_active' => true]);
        }

        // Activate subcategories that have is_active = 0 AND no products
        $inactiveSubIds = DB::table('subcategories')
            ->where('is_active', false)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('products')
                  ->whereColumn('products.subcategory_id', 'subcategories.id');
            })
            ->pluck('id');

        if ($inactiveSubIds->isNotEmpty()) {
            DB::table('subcategories')
                ->whereIn('id', $inactiveSubIds)
                ->update(['is_active' => true]);
        }
    }

    public function down(): void
    {
        // Not reversible — we don't know which ones were intentionally inactive
    }
};