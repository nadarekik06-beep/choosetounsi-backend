<?php

namespace App\Services;

use App\Models\UserPreference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ProductScoringService
 *
 * Computes a visibility score for each product in a collection.
 * Score determines the sort order of product listings.
 *
 * Formula:
 *   Score = UserInterestScore + SellerPriorityScore + ProductBoostScore
 *
 * UserInterestScore (requires authenticated user with preferences):
 *   +50  if product category matches user's preferred categories
 *   +20  if product's brand attribute matches user's preferred brands
 *   +15  if product gender attribute matches user's gender preference
 *
 * SellerPriorityScore (based on seller's active subscription plan):
 *   +40  Black plan
 *   +25  Red plan
 *   +10  Free/Green plan (default)
 *
 * ProductBoostScore (computed dynamically — no static columns needed):
 *   +20  Trending: product views rank in top 20% of all active products
 *   +15  New arrival: created within last 30 days
 *   +10  High rating: average review rating >= 4.0 (uses existing reviews if available)
 *   +8   Featured: product.featured = true
 *   +5   Low stock urgency: stock between 1 and 5 (creates urgency)
 *
 * Sponsorship bonus (already handled in the DB query layer by is_sponsored,
 * but we also add it here as a score component for unified ranking):
 *   +sponsored_priority  when is_sponsored = true
 *
 * Performance:
 *   - All scores are computed in PHP after a single DB query (no N+1)
 *   - Seller plans are fetched in a single batch query
 *   - Trending threshold is computed once per request
 *   - Brand matching uses the pre-loaded attributeValues relation
 */
class ProductScoringService
{
    // ── Score constants ────────────────────────────────────────────────────

    // UserInterestScore
    const SCORE_CATEGORY_MATCH = 50;
    const SCORE_BRAND_MATCH    = 20;
    const SCORE_GENDER_MATCH   = 15;

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
    const TRENDING_VIEW_PERCENTILE = 0.80;  // top 20% by views
    const NEW_ARRIVAL_DAYS         = 30;    // created within X days
    const HIGH_RATING_THRESHOLD    = 4.0;   // average rating >= X
    const LOW_STOCK_MAX            = 5;     // stock <= X triggers urgency boost

    /**
     * Score and sort a collection of Product Eloquent models.
     *
     * @param  Collection         $products   Products already loaded from DB
     * @param  UserPreference|null $prefs     Authenticated user's preferences (or null)
     * @return Collection                     Same products, sorted by score DESC
     */
    public function scoreAndSort(Collection $products, ?UserPreference $prefs = null): Collection
    {
        if ($products->isEmpty()) {
            return $products;
        }

        // ── Pre-compute lookup tables (batch queries, not per-product) ─────

        $sellerPlanMap    = $this->buildSellerPlanMap($products);
        $trendingThreshold = $this->computeTrendingThreshold();
        $ratingMap        = $this->buildRatingMap($products);

        // ── Score each product ─────────────────────────────────────────────

        return $products
            ->map(function ($product) use ($prefs, $sellerPlanMap, $trendingThreshold, $ratingMap) {
                $product->_score = $this->computeScore(
                    $product,
                    $prefs,
                    $sellerPlanMap,
                    $trendingThreshold,
                    $ratingMap
                );
                $product->_score_breakdown = $this->computeBreakdown(
                    $product,
                    $prefs,
                    $sellerPlanMap,
                    $trendingThreshold,
                    $ratingMap
                );
                return $product;
            })
            ->sortByDesc('_score')
            ->values();
    }

    /**
     * Compute the total score for a single product.
     * Called once per product — all lookup data is pre-built.
     */
    public function computeScore(
        $product,
        ?UserPreference $prefs,
        array $sellerPlanMap,
        int $trendingThreshold,
        array $ratingMap
    ): int {
        return $this->userInterestScore($product, $prefs)
             + $this->sellerPriorityScore($product, $sellerPlanMap)
             + $this->productBoostScore($product, $trendingThreshold, $ratingMap)
             + $this->sponsorshipBonus($product);
    }

    // ── Score components ───────────────────────────────────────────────────

    /**
     * UserInterestScore
     * Only applies when user has set preferences.
     * Returns 0 for guests or users without preferences.
     */
    private function userInterestScore($product, ?UserPreference $prefs): int
    {
        if (!$prefs || !$prefs->hasAnyPreference()) {
            return 0;
        }

        $score = 0;

        // +50 if product category is in user's preferred categories
        if (!empty($prefs->category_ids) && in_array($product->category_id, $prefs->category_ids)) {
            $score += self::SCORE_CATEGORY_MATCH;
        }

        // +20 if product has a brand attribute matching user's preferred brands
        if (!empty($prefs->brand_ids)) {
            $score += $this->matchesBrand($product, $prefs->brand_ids)
                ? self::SCORE_BRAND_MATCH
                : 0;
        }

        // +15 if product gender attribute matches user's gender preference
        if ($prefs->gender) {
            $score += $this->matchesGender($product, $prefs->gender)
                ? self::SCORE_GENDER_MATCH
                : 0;
        }

        return $score;
    }

    /**
     * SellerPriorityScore
     * Reads the seller's active plan from the pre-built map.
     */
    private function sellerPriorityScore($product, array $sellerPlanMap): int
    {
        $sellerId = $product->seller_id;
        if (!$sellerId) {
            return self::SCORE_PLAN_FREE; // platform products get base score
        }

        $plan = $sellerPlanMap[$sellerId] ?? 'free';

        return match ($plan) {
            'black' => self::SCORE_PLAN_BLACK,
            'red'   => self::SCORE_PLAN_RED,
            default => self::SCORE_PLAN_FREE,
        };
    }

    /**
     * ProductBoostScore
     * Computed dynamically — no static DB columns needed.
     */
    private function productBoostScore($product, int $trendingThreshold, array $ratingMap): int
    {
        $score = 0;

        // +20 Trending: views above the 80th percentile threshold
        if (($product->views ?? 0) >= $trendingThreshold && $trendingThreshold > 0) {
            $score += self::SCORE_TRENDING;
        }

        // +15 New arrival: created within last 30 days
        if ($product->created_at && $product->created_at->gte(now()->subDays(self::NEW_ARRIVAL_DAYS))) {
            $score += self::SCORE_NEW_ARRIVAL;
        }

        // +10 High rating: average review >= 4.0
        $avgRating = $ratingMap[$product->id] ?? 0;
        if ($avgRating >= self::HIGH_RATING_THRESHOLD) {
            $score += self::SCORE_HIGH_RATING;
        }

        // +8 Featured product
        if ($product->featured) {
            $score += self::SCORE_FEATURED;
        }

        // +5 Low stock urgency (1–5 items left)
        $stock = $product->stock ?? 0;
        if ($stock >= 1 && $stock <= self::LOW_STOCK_MAX) {
            $score += self::SCORE_LOW_STOCK;
        }

        return $score;
    }

    /**
     * Sponsorship bonus — adds the product's sponsored_priority when sponsored.
     * This stacks on top of the existing is_sponsored DB-level sort.
     */
    private function sponsorshipBonus($product): int
    {
        if ($product->is_sponsored) {
            return (int) ($product->sponsored_priority ?? 0);
        }
        return 0;
    }

    // ── Brand matching ─────────────────────────────────────────────────────

    /**
     * Checks if the product has a brand attribute value matching the user's preferences.
     * Brand is stored as a product_attribute_value where attribute.slug = 'brand'.
     * The value is a JSON-encoded array of attribute_option IDs, e.g. "[12]".
     *
     * Requires the product's attributeValues relation to be loaded with the attribute.
     */
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

            // Decode the stored value — could be "[12]" or "12"
            $decoded = json_decode($pav->value, true);
            if (!is_array($decoded)) {
                $decoded = [(int) $pav->value];
            }

            // Check if any brand option ID intersects with user preferences
            if (!empty(array_intersect($decoded, $preferredBrandIds))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the product's gender attribute matches the user's preference.
     * Gender is stored as a text attribute_value (e.g. "male", "female", "unisex").
     *
     * "unisex" matches any gender preference.
     */
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

            // Unisex matches any preference
            if ($productGender === 'unisex') {
                return true;
            }

            return $productGender === $preferredGender;
        }

        return false;
    }

    // ── Batch lookup builders ──────────────────────────────────────────────

    /**
     * Builds a map of seller_id => active_plan for all sellers in the collection.
     * Single query — avoids N+1.
     *
     * @return array<int, string>  e.g. [5 => 'red', 12 => 'black', 7 => 'free']
     */
    private function buildSellerPlanMap(Collection $products): array
    {
        $sellerIds = $products
            ->pluck('seller_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($sellerIds)) {
            return [];
        }

        // seller_applications.plan is the source of truth for active plan
        $rows = DB::table('seller_applications')
            ->whereIn('user_id', $sellerIds)
            ->where('status', 'approved')
            ->select('user_id', 'plan')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->user_id] = $row->plan ?? 'free';
        }

        return $map;
    }

    /**
     * Computes the views threshold for "trending" — 80th percentile.
     * Uses a single COUNT + ORDER BY query, not a full table scan.
     *
     * Returns 0 if no products exist (disables trending boost).
     */
    private function computeTrendingThreshold(): int
    {
        // Get total count of active products
        $total = DB::table('products')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->count();

        if ($total === 0) {
            return 0;
        }

        // The 80th percentile row offset
        $offset = (int) floor($total * self::TRENDING_VIEW_PERCENTILE);
        $offset = max(0, $total - $offset - 1); // convert to OFFSET from highest

        // Get the views value at that percentile
        $row = DB::table('products')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->where('views', '>', 0)
            ->orderByDesc('views')
            ->offset($offset)
            ->limit(1)
            ->value('views');

        return (int) ($row ?? PHP_INT_MAX); // if no views data, disable trending
    }

    /**
     * Builds a map of product_id => average_rating.
     * Reads from the product_reviews table if it exists.
     * Returns an empty map if no reviews table is present (graceful degradation).
     *
     * @return array<int, float>
     */
    private function buildRatingMap(Collection $products): array
    {
        $productIds = $products->pluck('id')->toArray();

        if (empty($productIds)) {
            return [];
        }

        // Check if product_reviews table exists — graceful degradation
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
            // If query fails for any reason, skip rating boost gracefully
            return [];
        }
    }

    // ── Debug helper ───────────────────────────────────────────────────────

    /**
     * Returns a human-readable breakdown of a product's score.
     * Useful for development and future admin panel display.
     */
    public function computeBreakdown(
        $product,
        ?UserPreference $prefs,
        array $sellerPlanMap,
        int $trendingThreshold,
        array $ratingMap
    ): array {
        $sellerId = $product->seller_id;
        $plan     = $sellerPlanMap[$sellerId] ?? 'free';
        $avgRating = $ratingMap[$product->id] ?? 0;
        $stock     = $product->stock ?? 0;

        return [
            'user_interest'    => $this->userInterestScore($product, $prefs),
            'seller_priority'  => $this->sellerPriorityScore($product, $sellerPlanMap),
            'product_boost'    => $this->productBoostScore($product, $trendingThreshold, $ratingMap),
            'sponsorship'      => $this->sponsorshipBonus($product),
            'total'            => $this->computeScore($product, $prefs, $sellerPlanMap, $trendingThreshold, $ratingMap),
            'details' => [
                'seller_plan'       => $plan,
                'views'             => $product->views ?? 0,
                'trending_threshold'=> $trendingThreshold,
                'is_trending'       => ($product->views ?? 0) >= $trendingThreshold && $trendingThreshold > 0,
                'is_new_arrival'    => $product->created_at?->gte(now()->subDays(self::NEW_ARRIVAL_DAYS)) ?? false,
                'avg_rating'        => $avgRating,
                'is_high_rated'     => $avgRating >= self::HIGH_RATING_THRESHOLD,
                'is_featured'       => (bool) $product->featured,
                'stock'             => $stock,
                'low_stock_bonus'   => $stock >= 1 && $stock <= self::LOW_STOCK_MAX,
                'is_sponsored'      => (bool) $product->is_sponsored,
            ],
        ];
    }
}