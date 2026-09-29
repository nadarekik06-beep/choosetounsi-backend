<?php

namespace App\Services\Ads;

use App\Models\Product;
use App\Services\Recommendation\SimilarProductsFinder;
use Illuminate\Support\Facades\DB;

/**
 * "Boost readiness": can this product turn paid clicks into orders? A product
 * that can't (unlisted, no stock, no photo, far pricier than similar items)
 * cannot be boosted; everything else gets a 0–100 score with fixable tips.
 *
 * Returns machine codes only — the seller dashboard translates them.
 *
 *   ['score' => 72, 'passes' => true, 'threshold' => 60,
 *    'blockers' => [['code' => 'out_of_stock', 'action' => 'restock']],
 *    'tips'     => [['code' => 'add_images', 'action' => 'edit_images', 'params' => ['count' => 1, 'target' => 3]]],
 *    'checks'   => ['images' => ['points' => 5, 'max' => 20, 'value' => 1], ...]]
 */
class ReadinessService
{
    // Points per check (sum = 100).
    const WEIGHTS = ['listed' => 20, 'stock' => 20, 'images' => 20, 'description' => 15, 'price' => 15, 'rating' => 10];

    const TARGET_IMAGES       = 3;
    const MIN_DESCRIPTION     = 120;   // characters
    const LOW_STOCK           = 3;
    const PRICE_TIP_RATIO     = 1.3;   // > median × this → tip
    const PRICE_BLOCK_RATIO   = 2.0;   // > median × this → blocker
    const MIN_COMPARABLES     = 3;
    const LOW_RATING          = 3.0;
    const MIN_REVIEWS_FOR_RATING = 3;

    public function __construct(
        private AdSettings $settings,
        private SimilarProductsFinder $similar,
    ) {}

    public function check(Product $product): array
    {
        $checks = $blockers = $tips = [];

        // ── Listed (approved + active) ──────────────────────────────────────
        $listed = $product->is_approved && $product->is_active && !$product->trashed();
        $checks['listed'] = $this->check_('listed', $listed ? 1.0 : 0.0, $listed);
        if (!$listed) {
            $blockers[] = ['code' => 'not_listed', 'action' => 'edit_product'];
        }

        // ── Stock (sum of active variants when the product has any) ────────
        $stock = $this->totalStock($product);
        $checks['stock'] = $this->check_('stock', $stock <= 0 ? 0.0 : ($stock < self::LOW_STOCK ? 0.5 : 1.0), $stock);
        if ($stock <= 0) {
            $blockers[] = ['code' => 'out_of_stock', 'action' => 'restock'];
        } elseif ($stock < self::LOW_STOCK) {
            $tips[] = ['code' => 'low_stock', 'action' => 'restock', 'params' => ['stock' => $stock]];
        }

        // ── Images ──────────────────────────────────────────────────────────
        $images = $product->images()->count();
        $checks['images'] = $this->check_('images', min(1.0, $images / self::TARGET_IMAGES), $images);
        if ($images === 0) {
            $blockers[] = ['code' => 'no_image', 'action' => 'edit_images'];
        } elseif ($images < self::TARGET_IMAGES) {
            $tips[] = ['code' => 'add_images', 'action' => 'edit_images', 'params' => ['count' => $images, 'target' => self::TARGET_IMAGES]];
        }

        // ── Description (the seller's own text) ─────────────────────────────
        $length = mb_strlen(trim(strip_tags((string) $product->getRawOriginal('description'))));
        $checks['description'] = $this->check_('description', min(1.0, $length / self::MIN_DESCRIPTION), $length);
        if ($length < self::MIN_DESCRIPTION) {
            $tips[] = ['code' => 'improve_description', 'action' => 'ai_description', 'params' => ['length' => $length, 'target' => self::MIN_DESCRIPTION]];
        }

        // ── Price vs similar products ───────────────────────────────────────
        $price  = (float) $product->price;
        $median = $this->similarMedianPrice($product);
        if ($median === null || $median <= 0) {
            $checks['price'] = $this->check_('price', 1.0, null);
        } else {
            $ratio = $price / $median;
            $params = ['price' => round($price, 3), 'median' => round($median, 3)];
            if ($ratio > self::PRICE_BLOCK_RATIO) {
                $checks['price'] = $this->check_('price', 0.0, round($ratio, 2));
                $blockers[] = ['code' => 'price_far_above_similar', 'action' => 'discount', 'params' => $params];
            } elseif ($ratio > self::PRICE_TIP_RATIO) {
                $checks['price'] = $this->check_('price', 0.4, round($ratio, 2));
                $tips[] = ['code' => 'price_above_similar', 'action' => 'discount', 'params' => $params];
            } else {
                $checks['price'] = $this->check_('price', 1.0, round($ratio, 2));
            }
        }

        // ── Rating (approved reviews) ───────────────────────────────────────
        $reviews = DB::table('reviews')->where('product_id', $product->id)->where('status', 'approved')
            ->selectRaw('COUNT(*) AS n, AVG(rating) AS avg')->first();
        $lowRating = ($reviews->n ?? 0) >= self::MIN_REVIEWS_FOR_RATING && (float) $reviews->avg < self::LOW_RATING;
        $checks['rating'] = $this->check_('rating', $lowRating ? 0.0 : 1.0, $reviews->n ? round((float) $reviews->avg, 2) : null);
        if ($lowRating) {
            $tips[] = ['code' => 'low_rating', 'action' => 'reviews', 'params' => ['rating' => round((float) $reviews->avg, 1), 'count' => (int) $reviews->n]];
        }

        $score     = (int) round(array_sum(array_column($checks, 'points')));
        $threshold = $this->settings->int('readiness_threshold');

        return [
            'score'     => $score,
            'threshold' => $threshold,
            'passes'    => empty($blockers) && $score >= $threshold,
            'blockers'  => $blockers,
            'tips'      => $tips,
            'checks'    => $checks,
        ];
    }

    /** Stock buyers can actually order: the sum over active variants, else the product's own stock. */
    public function totalStock(Product $product): int
    {
        $variants = $product->activeVariants()->pluck('stock');
        return $variants->isNotEmpty() ? (int) $variants->sum() : (int) $product->stock;
    }

    private function check_(string $key, float $ratio, $value): array
    {
        $max = self::WEIGHTS[$key];
        return ['points' => round($max * max(0.0, min(1.0, $ratio)), 1), 'max' => $max, 'value' => $value];
    }

    /**
     * Median price of the most similar products (AI + content similarity), falling
     * back to the subcategory, then category, median. Null without enough comparables.
     */
    private function similarMedianPrice(Product $product): ?float
    {
        $ids = [];
        try {
            $ids = array_keys($this->similar->similarTo([$product->id => 1.0], 20)['scores'] ?? []);
        } catch (\Throwable $e) {
            // similarity is best-effort; fall back to the catalog median
        }

        $prices = $ids ? $this->availablePrices(fn ($q) => $q->whereIn('id', $ids), $product->id) : collect();
        if ($prices->count() < self::MIN_COMPARABLES && $product->subcategory_id) {
            $prices = $this->availablePrices(fn ($q) => $q->where('subcategory_id', $product->subcategory_id), $product->id);
        }
        if ($prices->count() < self::MIN_COMPARABLES && $product->category_id) {
            $prices = $this->availablePrices(fn ($q) => $q->where('category_id', $product->category_id), $product->id);
        }

        return $prices->count() >= self::MIN_COMPARABLES ? (float) $prices->median() : null;
    }

    private function availablePrices(callable $scope, int $excludeId)
    {
        return tap(Product::available()->where('id', '!=', $excludeId), $scope)
            ->limit(200)->pluck('price')->map(fn ($p) => (float) $p)->filter(fn ($p) => $p > 0)->values();
    }
}
