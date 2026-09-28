<?php

namespace App\Services\Recommendation;

use App\Models\UserInterestProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Per-user (or per-guest) interest profile built from user_interactions.
 *
 * Each signal contributes weight × 2^(−age_days / half_life) to the product's
 * category, subcategory, seller and brand, and to a log-price distribution.
 * Every affinity map is normalized to 0..1 (1 = strongest interest).
 *
 * Profiles are cached and persisted in user_interest_profiles. They're rebuilt
 * only when InteractionTracker marked the actor dirty after the last build,
 * or when the build is older than profile_ttl_minutes.
 */
class InterestProfileService
{
    const VERSION = 1;

    /** How much each affinity dimension contributes to a product's personal score (sums to 1). */
    const AFFINITY_WEIGHTS = [
        'category'    => 0.30,
        'subcategory' => 0.25,
        'seller'      => 0.20,
        'brand'       => 0.10,
        'price'       => 0.15,
    ];

    const MAX_EVENTS  = 5000;
    const MAP_SIZE    = 30;
    const MIN_SPREAD  = 0.35;   // ≈ ±40% around the preferred price

    public function forActor(?int $userId, ?string $sessionId, bool $force = false): array
    {
        if (!$userId && !$sessionId) {
            return $this->emptyProfile(null, null);
        }
        if ($userId) {
            $sessionId = null;   // a logged-in user's profile is keyed by user only
        }

        $dirtyAt  = (int) Cache::get(InteractionTracker::dirtyKey($userId, $sessionId), 0);
        $cacheKey = self::cacheKey($userId, $sessionId);

        if (!$force) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && $this->isFresh($cached, $dirtyAt)) {
                return $cached;
            }

            $row = UserInterestProfile::where($userId ? 'user_id' : 'session_id', $userId ?: $sessionId)->first();
            if ($row && $this->isFresh($row->profile, $dirtyAt)) {
                Cache::put($cacheKey, $row->profile, now()->addMinutes($this->ttl()));
                return $row->profile;
            }
        }

        return $this->rebuild($userId, $sessionId);
    }

    public function rebuild(?int $userId, ?string $sessionId): array
    {
        $profile = $this->compute($userId, $sessionId);

        UserInterestProfile::updateOrCreate(
            $userId ? ['user_id' => $userId] : ['session_id' => $sessionId],
            ['profile' => $profile, 'signal_count' => $profile['signal_count'], 'computed_at' => now()]
        );
        Cache::put(self::cacheKey($userId, $sessionId), $profile, now()->addMinutes($this->ttl()));

        return $profile;
    }

    public function forget(?int $userId, ?string $sessionId): void
    {
        Cache::forget(self::cacheKey($userId, $sessionId));
        UserInterestProfile::where($userId ? 'user_id' : 'session_id', $userId ?: $sessionId)->delete();
    }

    public static function cacheKey(?int $userId, ?string $sessionId): string
    {
        return 'reco:profile:v' . self::VERSION . ':' . ($userId ? "u{$userId}" : "s{$sessionId}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Build
    // ─────────────────────────────────────────────────────────────────────────

    public function compute(?int $userId, ?string $sessionId): array
    {
        $weights  = config('recommendations.weights');
        $halfLife = (float) config('recommendations.half_life_days', 14);

        $query = DB::table('user_interactions')
            ->where('created_at', '>=', now()->subDays((int) config('recommendations.profile_window_days', 120)))
            ->select('product_id', 'seller_id', 'category_id', 'event_type', 'order_id', 'created_at')
            ->orderByDesc('created_at')
            ->limit(self::MAX_EVENTS);
        $userId
            ? $query->where('user_id', $userId)
            : $query->where('session_id', $sessionId)->whereNull('user_id');
        $events = $query->get();

        $invalidPurchases = $this->cancelledPurchaseKeys($events);
        $productIds = $events->pluck('product_id')->filter()->unique()->values()->all();
        $products   = $this->productMeta($productIds);
        $brands     = $this->brandMap($productIds);

        $categories = $subcategories = $sellers = $brandScores = [];
        $counts = [];
        $priceW = $priceSum = $priceSq = 0.0;
        $now = now()->timestamp;
        $signals = 0;

        foreach ($events as $e) {
            $w = (float) ($weights[$e->event_type] ?? 0);
            if ($w == 0.0) {
                continue;
            }
            if ($e->event_type === 'purchase' && $e->order_id
                && isset($invalidPurchases["{$e->order_id}:{$e->product_id}"])) {
                continue;   // order later cancelled or refunded → the signal is withdrawn
            }

            $ageDays = max(0, $now - strtotime($e->created_at)) / 86400;
            $v = $w * pow(2, -$ageDays / $halfLife);

            $p     = $e->product_id ? ($products[$e->product_id] ?? null) : null;
            $catId = $e->category_id ?: ($p->category_id ?? null);
            $selId = $e->seller_id ?: ($p->seller_id ?? null);

            if ($catId)                   $categories[$catId]              = ($categories[$catId] ?? 0) + $v;
            if ($p && $p->subcategory_id) $subcategories[$p->subcategory_id] = ($subcategories[$p->subcategory_id] ?? 0) + $v;
            if ($selId)                   $sellers[$selId]                 = ($sellers[$selId] ?? 0) + $v;
            if ($p && isset($brands[$p->id])) $brandScores[$brands[$p->id]] = ($brandScores[$brands[$p->id]] ?? 0) + $v;

            if ($p && $v > 0 && (float) $p->price > 0) {
                $lp = log((float) $p->price);
                $priceW   += $v;
                $priceSum += $v * $lp;
                $priceSq  += $v * $lp * $lp;
            }

            $counts[$e->event_type] = ($counts[$e->event_type] ?? 0) + 1;
            $signals++;
        }

        // Following a seller is a standing preference: keep it alive even after the event decays.
        $followed = $userId
            ? DB::table('seller_follows')->where('user_id', $userId)->pluck('seller_id')->map(fn ($id) => (int) $id)->all()
            : [];
        foreach ($followed as $sid) {
            $sellers[$sid] = ($sellers[$sid] ?? 0) + 0.5 * (float) ($weights['follow'] ?? 4);
        }

        $price = null;
        if ($priceW > 0) {
            $mu     = $priceSum / $priceW;
            $spread = max(self::MIN_SPREAD, sqrt(max(0, $priceSq / $priceW - $mu * $mu)));
            $price  = [
                'center' => round(exp($mu), 2),
                'low'    => round(exp($mu - $spread), 2),
                'high'   => round(exp($mu + $spread), 2),
                'mu'     => round($mu, 5),
                'spread' => round($spread, 5),
            ];
        }

        return [
            'version'             => self::VERSION,
            'user_id'             => $userId,
            'session_id'          => $sessionId,
            'computed_at'         => $now,
            'is_cold'             => $signals === 0 && empty($followed),
            'signal_count'        => $signals,
            'event_counts'        => $counts,
            'categories'          => $this->normalize($categories),
            'subcategories'       => $this->normalize($subcategories),
            'sellers'             => $this->normalize($sellers),
            'brands'              => $this->normalize($brandScores),
            'price'               => $price,
            'followed_seller_ids' => $followed,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scoring helpers used by the feed
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Personal affinity of one product, 0..1, with the per-dimension breakdown.
     *
     * @param object|array $product  needs category_id, subcategory_id, seller_id, price
     */
    public function scoreProduct(array $profile, $product, ?string $brand = null): array
    {
        $p = (object) $product;
        $parts = [
            'category'    => (float) ($profile['categories'][$p->category_id ?? 0] ?? 0),
            'subcategory' => (float) ($profile['subcategories'][$p->subcategory_id ?? 0] ?? 0),
            'seller'      => (float) ($profile['sellers'][$p->seller_id ?? 0] ?? 0),
            'brand'       => $brand ? (float) ($profile['brands'][$brand] ?? 0) : 0.0,
            'price'       => $this->priceFit($profile['price'] ?? null, (float) ($p->price ?? 0)),
        ];

        $score = 0.0;
        foreach (self::AFFINITY_WEIGHTS as $dim => $w) {
            $score += $w * $parts[$dim];
        }

        return ['score' => round($score, 4), 'parts' => array_map(fn ($v) => round($v, 3), $parts)];
    }

    public function priceFit(?array $price, float $amount): float
    {
        if (!$price || $amount <= 0) {
            return 0.0;
        }
        $z = (log($amount) - $price['mu']) / $price['spread'];
        return exp(-0.5 * $z * $z);
    }

    /** product_id => normalized brand name (free-text "brand" attribute). */
    public function brandMap(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->where('a.slug', 'brand')
            ->whereIn('pav.product_id', $productIds)
            ->pluck('pav.value', 'pav.product_id')
            ->map(fn ($v) => self::normalizeBrand($v))
            ->filter()
            ->all();
    }

    public static function normalizeBrand($value): ?string
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;
        $value   = is_string($decoded) ? $decoded : $value;
        $value   = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $value)));
        return $value !== '' ? mb_substr($value, 0, 60) : null;
    }

    /**
     * Products this user bought (non-cancelled orders) that must not be recommended again.
     * Consumables (config: recommendations.repurchase) come back after N days.
     */
    public function purchasedExclusions(?int $userId): array
    {
        if (!$userId) {
            return [];
        }

        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->where('o.user_id', $userId)
            ->whereNotNull('oi.product_id')
            ->whereNotIn('o.status', ['cancelled', 'refunded'])
            ->where(fn ($q) => $q->whereNull('so.status')->orWhereNotIn('so.status', ['cancelled', 'refunded']))
            ->groupBy('oi.product_id', 'p.category_id', 'p.subcategory_id')
            ->select('oi.product_id', 'p.category_id', 'p.subcategory_id', DB::raw('MAX(o.created_at) as last_bought'))
            ->get();

        [$consumableCats, $consumableSubs] = $this->consumableIds();
        $cutoff = now()->subDays((int) config('recommendations.repurchase.after_days', 30))->toDateTimeString();

        return $rows->reject(function ($r) use ($consumableCats, $consumableSubs, $cutoff) {
            $consumable = in_array((int) $r->category_id, $consumableCats, true)
                       || in_array((int) $r->subcategory_id, $consumableSubs, true);
            return $consumable && $r->last_bought && $r->last_bought < $cutoff;
        })->pluck('product_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return array{0: int[], 1: int[]} consumable category ids, subcategory ids */
    public function consumableIds(): array
    {
        $cfg = config('recommendations.repurchase');
        return Cache::remember('reco:consumable_ids:' . md5(json_encode($cfg)), 3600, fn () => [
            DB::table('categories')->whereIn('slug', $cfg['category_slugs'] ?? [])->pluck('id')->map(fn ($i) => (int) $i)->all(),
            DB::table('subcategories')->whereIn('slug', $cfg['subcategory_slugs'] ?? [])->pluck('id')->map(fn ($i) => (int) $i)->all(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /** "order_id:product_id" keys of purchase events whose order/sub-order was cancelled or refunded. */
    private function cancelledPurchaseKeys(Collection $events): array
    {
        $purchases = $events->where('event_type', 'purchase')->whereNotNull('order_id');
        if ($purchases->isEmpty()) {
            return [];
        }
        $orderIds = $purchases->pluck('order_id')->unique()->values()->all();
        $dead     = ['cancelled', 'refunded'];

        // Whole order dead → every product in it (covers pack contents too).
        $deadOrders = DB::table('orders')->whereIn('id', $orderIds)->whereIn('status', $dead)
            ->pluck('id')->flip();

        // Only one seller's part cancelled → just that seller's items.
        $deadItems = DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->whereIn('oi.order_id', $orderIds)
            ->whereIn('so.status', $dead)
            ->get(['oi.order_id', 'oi.product_id']);

        $keys = [];
        foreach ($purchases as $e) {
            if (isset($deadOrders[$e->order_id])) {
                $keys["{$e->order_id}:{$e->product_id}"] = true;
            }
        }
        foreach ($deadItems as $i) {
            $keys["{$i->order_id}:{$i->product_id}"] = true;
        }
        return $keys;
    }

    private function productMeta(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        return DB::table('products')
            ->whereIn('id', $productIds)
            ->get(['id', 'category_id', 'subcategory_id', 'seller_id', 'price'])
            ->keyBy('id')
            ->all();
    }

    private function normalize(array $scores): array
    {
        $scores = array_filter($scores, fn ($v) => $v > 0);
        if (empty($scores)) {
            return [];
        }
        arsort($scores);
        $scores = array_slice($scores, 0, self::MAP_SIZE, true);
        $max = max($scores);
        return array_map(fn ($v) => round($v / $max, 4), $scores);
    }

    private function isFresh(array $profile, int $dirtyAt): bool
    {
        $at = (int) ($profile['computed_at'] ?? 0);
        return ($profile['version'] ?? 0) === self::VERSION
            && $at > $dirtyAt
            && $at >= now()->subMinutes($this->ttl())->timestamp;
    }

    private function ttl(): int
    {
        return (int) config('recommendations.profile_ttl_minutes', 30);
    }

    private function emptyProfile(?int $userId, ?string $sessionId): array
    {
        return [
            'version' => self::VERSION, 'user_id' => $userId, 'session_id' => $sessionId,
            'computed_at' => now()->timestamp, 'is_cold' => true, 'signal_count' => 0,
            'event_counts' => [], 'categories' => [], 'subcategories' => [], 'sellers' => [],
            'brands' => [], 'price' => null, 'followed_seller_ids' => [],
        ];
    }
}
