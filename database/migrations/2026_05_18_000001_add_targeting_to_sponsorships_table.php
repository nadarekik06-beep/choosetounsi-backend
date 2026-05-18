<?php
// database/migrations/2026_05_18_000001_add_targeting_to_sponsorships_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds Facebook-style audience targeting columns to sponsorships.
 *
 * All columns are NULLABLE — a null value means "no filter / show to all".
 * This preserves backward compatibility: existing sponsorships have null
 * targeting and continue to show to everyone (current behaviour).
 *
 * Targeting is enforced at query time in SponsorshipController::publicFeed()
 * and in the new RecommendationController::feed().
 *
 * Columns:
 *   target_gender        — 'male'|'female'|'unisex'|null
 *   target_wilaya_ids    — JSON array of wilaya strings, e.g. ["Tunis","Sfax"]
 *   target_category_ids  — JSON array of category IDs, e.g. [1, 3, 7]
 *   target_price_min     — minimum price (user's preferred price_min must be ≥ this)
 *   target_price_max     — maximum price (user's preferred price_max must be ≤ this)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sponsorships', function (Blueprint $table) {
            // Gender targeting — null = show to all genders
            $table->enum('target_gender', ['male', 'female', 'unisex'])
                  ->nullable()
                  ->after('ai_ad_copy')
                  ->comment('null = no gender filter');

            // Wilaya targeting — null = show to all regions
            // Stored as JSON array: ["Tunis", "Sfax", "Sousse"]
            $table->json('target_wilaya_ids')
                  ->nullable()
                  ->after('target_gender')
                  ->comment('JSON array of wilaya names; null = all regions');

            // Category targeting — null = show in all categories
            // Stored as JSON array of category IDs: [1, 3, 7]
            $table->json('target_category_ids')
                  ->nullable()
                  ->after('target_wilaya_ids')
                  ->comment('JSON array of category IDs; null = all categories');

            // Budget/price targeting
            $table->decimal('target_price_min', 10, 3)
                  ->nullable()
                  ->after('target_category_ids')
                  ->comment('Target users whose price_min >= this value; null = no filter');

            $table->decimal('target_price_max', 10, 3)
                  ->nullable()
                  ->after('target_price_min')
                  ->comment('Target users whose price_max <= this value; null = no filter');
        });
    }

    public function down(): void
    {
        Schema::table('sponsorships', function (Blueprint $table) {
            $table->dropColumn([
                'target_gender',
                'target_wilaya_ids',
                'target_category_ids',
                'target_price_min',
                'target_price_max',
            ]);
        });
    }
};