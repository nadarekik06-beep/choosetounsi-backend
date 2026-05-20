<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: ensure Color and Size attributes are marked as is_variant = true
 * on every subcategory they are assigned to.
 *
 * Root cause: the admin panel attribute assignment defaulted is_variant = false
 * for some attributes, causing them to be treated as "info only" instead of
 * variant axes. This means sellers see "no variant attributes configured"
 * even after the admin assigns Color/Size to a subcategory.
 *
 * This migration:
 *   1. Sets is_variant = 1 for all subcategory_attributes rows where the
 *      attribute slug is a known variant axis (color, size, and common
 *      variants like ram, storage, weight).
 *   2. Sets is_variant = 0 for known info-only attributes (brand, material,
 *      condition, gender, sleeve-type, fit, age-group) if they were
 *      accidentally marked as variant.
 *
 * Safe to re-run — uses UPDATE with WHERE, never deletes data.
 */
return new class extends Migration
{
    // Attribute slugs that should ALWAYS be variant axes
    private array $variantSlugs = [
        'color',
        'colour',
        'size',
        'taille',
        'couleur',
        'ram',
        'storage',
        'capacity',
        'weight',
        'voltage',
    ];

    // Attribute slugs that should ALWAYS be info-only (never variant)
    private array $infoSlugs = [
        'brand',
        'material',
        'condition',
        'gender',
        'sleeve-type',
        'fit',
        'age-group',
        'description',
        'origin',
        'warranty',
    ];

    public function up(): void
    {
        // ── 1. Force is_variant = 1 for known variant axes ─────────────────
        if (!empty($this->variantSlugs)) {
            $attrIds = DB::table('attributes')
                ->whereIn('slug', $this->variantSlugs)
                ->pluck('id');

            if ($attrIds->isNotEmpty()) {
                DB::table('subcategory_attributes')
                    ->whereIn('attribute_id', $attrIds)
                    ->update(['is_variant' => 1]);
            }
        }

        // ── 2. Force is_variant = 0 for known info-only attributes ─────────
        if (!empty($this->infoSlugs)) {
            $infoIds = DB::table('attributes')
                ->whereIn('slug', $this->infoSlugs)
                ->pluck('id');

            if ($infoIds->isNotEmpty()) {
                DB::table('subcategory_attributes')
                    ->whereIn('attribute_id', $infoIds)
                    ->update(['is_variant' => 0]);
            }
        }
    }

    public function down(): void
    {
        // No safe way to reverse data fixes — this is intentional.
        // The original data was already incorrect (is_variant defaulted to 0).
    }
};