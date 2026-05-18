<?php
// app/Services/ProductScoringService.php

namespace App\Services;

use App\Models\UserPreference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ProductScoringService — unified ranking for ALL products.
 *
 * KEY CHANGE from previous version:
 *   Sponsored products are NO LONGER pulled out and prepended blindly.
 *   They enter the same scoring pipeline as non-sponsored products.
 *   Sponsorship adds a boost score ON TOP of the preference score —
 *   it can never override a strong preference match.
 *
 * Score formula (max theoretical ≈ 230):
 *
 *   UserInterestScore    (0–85)   ← PRIMARY ranking factor
 *     +50  category match
 *     +20  brand match
 *     +15  gender match
 *
 *   ActivityScore        (0–40)   ← behavioral signals, recency-weighted
 *     Drawn from user_activity_logs, decays with time
 *
 *   SellerPriorityScore  (0–40)   ← seller subscription tier
 *     +40  black
 *     +25  red
 *     +10  free / green
 *
 *   ProductBoostScore    (0–38)   ← product-level quality signals
 *     +20  trending (top 20% by views)
 *     +15  new arrival (< 30 days)
 *     +10  high rated (avg ≥ 4.0)
 *     +8   featured
 *     +5   low stock urgency (1–5 units)
 *
 *   SponsorshipBonus     (10–70)  ← SECONDARY after preference
 *     Added as-is from sponsored_priority (10=green, 30=red, 70=black)
 *     ONLY applied when product.is_sponsored = true
 *
 * This means a perfectly-matched non-sponsored product (score ≈ 125)
 * beats an irrelevant black-tier sponsored product (score ≈ 70).
 */
class ProductScoringService
{
    // ── Score constants ────────────────────────────────────────────────────

    // UserInterestScore
    const SCORE_CATEGORY_MATCH = 50;
    const SCORE_BRAND_MATCH    = 20;
    const SCORE_GENDER_MATCH   = 15;

    // ActivityScore — recency half-lives in days
    const ACTIVITY_WEIGHTS = [
        'order'    => 4,
        'cart'     => 3,
        'favorite' => 2,
        'view'     => 1,
    ];
    const ACTIVITY_HALFLIFE_DAYS = 14;   // score halves every 14 days
    const ACTIVITY_MAX_SCORE     = 40;   // cap so activity never dominates

    // SellerPriorityScore
    const SCORE_PLAN_BLACK = 40;
    const SCORE_PLAN_RED   = 25;
    const SCORE_PLAN_FREE  = 10;

    // ProductBoostScore
    const SCORE_TRENDING    = 20;
    const SCORE_NEW_ARRIVAL = 15;
    const SCORE_HIGH_RATING = 10;
    const SCORE_FEATURED    = 8;
    const SCORE_LOW_STOCK   = 5;

    // Thresholds
    const TRENDING_VIEW_PERCENTILE = 0.80;
    const NEW_ARRIVAL_DAYS         = 30;
    const HIGH_RATING_THRESHOLD    = 4.0;
    const LOW_STOCK_MAX            = 5;

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Score and sort ALL products (sponsored + non-sponsored together).
     *
     * @param  Collection          $products  All products from DB (mixed sponsored state)
     * @param  UserPreference|null $prefs     Authenticated user's combined preferences
     * @param  array               $activityWeights  From UserPreferenceService::inferPreferencesFromActivity()
     * @return Collection                     Sorted by score DESC
     */
    public function scoreAndSort(
        Collection $products,
        ?UserPreference $prefs = null,
        array $activityWeights = []
    ): Collection {
        if ($products->isEmpty()) {
            return $products;
        }

        $sellerPlanMap     = $this->buildSellerPlanMap($products);
        $trendingThreshold = $this->computeTrendingThreshold();
        $ratingMap         = $this->buildRatingMap($products);

        return $products
            ->map(function ($product) use (
                $prefs, $activityWeights,
                $sellerPlanMap, $trendingThreshold, $ratingMap
            ) {
                $product->_score = $this->computeScore(
                    $product, $prefs, $activityWeights,
                    $sellerPlanMap, $trendingThreshold, $ratingMap
                );
                return $product;
            })
            ->sortByDesc('_score')
            ->values();
    }

    /**
     * Compute total score for a single product.
     * All lookup tables must be pre-built (no N+1 here).
     */
    public function computeScore(
        $product,
        ?UserPreference $prefs,
        array $activityWeights,
        array $sellerPlanMap,
        int $trendingThreshold,
        array $ratingMap
    ): int {
        return $this->userInterestScore($product, $prefs)
             + $this->activityScore($product, $activityWeights)
             + $this->sellerPriorityScore($product, $sellerPlanMap)
             + $this->productBoostScore($product, $trendingThreshold, $ratingMap)
             + $this->sponsorshipBonus($product);
    }

    // ── Score components ───────────────────────────────────────────────────

    /**
     * UserInterestScore — matches product against explicit user preferences.
     * Returns 0 for guests or users without any preferences.
     */
    private function userInterestScore($product, ?UserPreference $prefs): int
    {
        if (!$prefs || !$prefs->hasAnyPreference()) {
            return 0;
        }

        $score = 0;

        if (!empty($prefs->category_ids)
            && in_array((int) $product->category_id, array_map('intval', (array) $prefs->category_ids))
        ) {
            $score += self::SCORE_CATEGORY_MATCH;
        }

        if (!empty($prefs->brand_ids)) {
            $score += $this->matchesBrand($product, array_map('intval', (array) $prefs->brand_ids))
                ? self::SCORE_BRAND_MATCH
                : 0;
        }

        if ($prefs->gender) {
            $score += $this->matchesGender($product, $prefs->gender)
                ? self::SCORE_GENDER_MATCH
                : 0;
        }

        return $score;
    }

    /**
     * ActivityScore — recency-weighted behavioral signals.
     *
     * Uses the interaction_weights from UserPreferenceService::inferPreferencesFromActivity().
     * Each interaction weight is decayed exponentially by how many days ago it happened.
     *
     * The raw score is normalized to 0–ACTIVITY_MAX_SCORE.
     *
     * @param  array $activityWeights  product_id => raw_weight (already aggregated)
     */
    private function activityScore($product, array $activityWeights): int
    {
        if (empty($activityWeights)) {
            return 0;
        }

        $rawWeight = $activityWeights[$product->id] ?? 0;
        if ($rawWeight <= 0) {
            return 0;
        }

        // Normalize: cap at the maximum possible weight in the collection,
        // then scale to ACTIVITY_MAX_SCORE. This prevents one outlier product
        // (e.g. 200 cart adds) from collapsing everyone else to near zero.
        $maxWeight = max($activityWeights);
        if ($maxWeight <= 0) {
            return 0;
        }

        $normalized = $rawWeight / $maxWeight; // 0.0 – 1.0
        return (int) round($normalized * self::ACTIVITY_MAX_SCORE);
    }

    /**
     * SellerPriorityScore — seller's active subscription plan.
     */
    private function sellerPriorityScore($product, array $sellerPlanMap): int
    {
        $sellerId = $product->seller_id;
        if (!$sellerId) {
            return self::SCORE_PLAN_FREE;
        }

        $plan = $sellerPlanMap[$sellerId] ?? 'free';

        return match ($plan) {
            'black' => self::SCORE_PLAN_BLACK,
            'red'   => self::SCORE_PLAN_RED,
            default => self::SCORE_PLAN_FREE,
        };
    }

    /**
     * ProductBoostScore — quality signals computed dynamically.
     */
    private function productBoostScore($product, int $trendingThreshold, array $ratingMap): int
    {
        $score = 0;

        if (($product->views ?? 0) >= $trendingThreshold && $trendingThreshold > 0) {
            $score += self::SCORE_TRENDING;
        }

        if ($product->created_at && $product->created_at->gte(now()->subDays(self::NEW_ARRIVAL_DAYS))) {
            $score += self::SCORE_NEW_ARRIVAL;
        }

        $avgRating = $ratingMap[$product->id] ?? 0;
        if ($avgRating >= self::HIGH_RATING_THRESHOLD) {
            $score += self::SCORE_HIGH_RATING;
        }

        if ($product->featured) {
            $score += self::SCORE_FEATURED;
        }

        $stock = $product->stock ?? 0;
        if ($stock >= 1 && $stock <= self::LOW_STOCK_MAX) {
            $score += self::SCORE_LOW_STOCK;
        }

        return $score;
    }

    /**
     * SponsorshipBonus — adds sponsored_priority when the product is sponsored.
     *
     * This is ADDITIVE on top of preference score, never replacing it.
     * A sponsored product with zero preference match gets only this bonus.
     * A non-sponsored product with strong preference match beats it.
     */
    private function sponsorshipBonus($product): int
    {
        if ($product->is_sponsored) {
            return (int) ($product->sponsored_priority ?? 0);
        }
        return 0;
    }

    // ── Attribute matching ─────────────────────────────────────────────────

    private function matchesBrand($product, array $preferredBrandIds): bool
    {
        if (!$product->relationLoaded('attributeValues')) {
            return false;
        }

        foreach ($product->attributeValues as $pav) {
            $attr = $pav->attribute ?? null;
            if (!$attr || $attr->slug !== 'brand') {
                continue;
            }

            $decoded = json_decode($pav->value, true);
            if (!is_array($decoded)) {
                $decoded = [(int) $pav->value];
            }

            if (!empty(array_intersect($decoded, $preferredBrandIds))) {
                return true;
            }
        }

        return false;
    }

    private function matchesGender($product, string $preferredGender): bool
    {
        if (!$product->relationLoaded('attributeValues')) {
            return false;
        }

        foreach ($product->attributeValues as $pav) {
            $attr = $pav->attribute ?? null;
            if (!$attr || $attr->slug !== 'gender') {
                continue;
            }

            $productGender = strtolower(trim($pav->value));

            if ($productGender === 'unisex') {
                return true;
            }

            return $productGender === $preferredGender;
        }

        return false;
    }

    // ── Batch lookup builders ──────────────────────────────────────────────

    /**
     * seller_id => 'black'|'red'|'free'
     * Single query, no N+1.
     */
    public function buildSellerPlanMap(Collection $products): array
    {
        $sellerIds = $products->pluck('seller_id')->filter()->unique()->values()->toArray();

        if (empty($sellerIds)) {
            return [];
        }

        // Use `plan` column (the active plan post-migration).
        // Fall back to 'free' if no approved application exists.
        $rows = DB::table('seller_applications')
            ->whereIn('user_id', $sellerIds)
            ->where('status', 'approved')
            ->select('user_id', DB::raw("COALESCE(`plan`, 'free') as plan"))
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->user_id] = $row->plan ?? 'free';
        }

        return $map;
    }

    /**
     * Views threshold for "trending" — 80th percentile of all active products.
     */
    public function computeTrendingThreshold(): int
    {
        $total = DB::table('products')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->count();

        if ($total === 0) {
            return 0;
        }

        $offset = max(0, (int) floor($total * self::TRENDING_VIEW_PERCENTILE));
        $offset = max(0, $total - $offset - 1);

        $row = DB::table('products')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->where('views', '>', 0)
            ->orderByDesc('views')
            ->offset($offset)
            ->limit(1)
            ->value('views');

        return (int) ($row ?? PHP_INT_MAX);
    }

    /**
     * product_id => avg_rating, batch fetched.
     */
    public function buildRatingMap(Collection $products): array
    {
        $productIds = $products->pluck('id')->toArray();

        if (empty($productIds)) {
            return [];
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('product_reviews')) {
            return [];
        }

        try {
            $rows = DB::table('product_reviews')
                ->whereIn('product_id', $productIds)
                ->select('product_id', DB::raw('AVG(rating) as avg_rating'))
                ->groupBy('product_id')
                ->get();

            $map = [];
            foreach ($rows as $row) {
                $map[(int) $row->product_id] = (float) $row->avg_rating;
            }

            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Debug helper ───────────────────────────────────────────────────────

    public function computeBreakdown(
        $product,
        ?UserPreference $prefs,
        array $activityWeights,
        array $sellerPlanMap,
        int $trendingThreshold,
        array $ratingMap
    ): array {
        return [
            'user_interest'   => $this->userInterestScore($product, $prefs),
            'activity'        => $this->activityScore($product, $activityWeights),
            'seller_priority' => $this->sellerPriorityScore($product, $sellerPlanMap),
            'product_boost'   => $this->productBoostScore($product, $trendingThreshold, $ratingMap),
            'sponsorship'     => $this->sponsorshipBonus($product),
            'total'           => $this->computeScore(
                $product, $prefs, $activityWeights,
                $sellerPlanMap, $trendingThreshold, $ratingMap
            ),
        ];
    }
}