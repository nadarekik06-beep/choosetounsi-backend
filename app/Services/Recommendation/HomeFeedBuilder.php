<?php

namespace App\Services\Recommendation;

use App\Models\Product;
use App\Models\Sponsorship;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Assembles the personalized homepage: every section in one response, with
 * GLOBAL DEDUPLICATION — a product appears in at most one section.
 *
 * Sections claim products from most specific to most generic — paid, the
 * viewer's own lists, favourite sellers, recommended, similar, trending — so a
 * narrow row never loses its few candidates to a broad one. Display order differs.
 *
 *   Warm (has activity):  recommended, sponsored, trending, similar,
 *                         favorites, favorite_sellers, recently_viewed
 *   Cold (guest/new):     sponsored, trending, new_arrivals, top_rated,
 *                         popular_in_category (one row per top category)
 *
 * Diversity: per-row caps per seller / category, ~15% exploration slots in
 * "recommended", and a seeded ±jitter that changes every 5 minutes so the
 * page rotates between visits but stays stable while browsing.
 */
class HomeFeedBuilder
{
    const DISPLAY_ORDER = [
        'recommended', 'sponsored', 'trending', 'similar', 'favorites',
        'favorite_sellers', 'recently_viewed', 'new_arrivals', 'top_rated', 'popular_in_category',
    ];

    /** Recommended = personal affinity + a little popularity/quality + seller plan as a tie-breaker. */
    const RECOMMENDED_MIX = ['affinity' => 0.70, 'popularity' => 0.15, 'quality' => 0.10];

    const SEEN_PENALTY = 0.3;
    const ROTATION_SECONDS = 300;
    const POPULAR_CATEGORY_ROWS = 3;
    const SPONSORED_INJECT_SLOTS = [1, 5];

    private array $cfg;
    private array $pool;
    private array $profile;
    private array $claimed = [];
    private array $excluded = [];
    private array $explain = [];
    private array $paid = [];
    private int $seed = 0;

    public function __construct(
        private CandidatePools $pools,
        private InterestProfileService $profiles,
        private SimilarProductsFinder $similar,
        private ProductCardPresenter $presenter,
    ) {}

    public function build(?int $userId, ?string $sessionId, bool $explain = false): array
    {
        $this->cfg      = config('recommendations.feed');
        $this->pool     = $this->pools->get();
        $this->profile  = $this->profiles->forActor($userId, $sessionId);
        $this->claimed  = $this->explain = $this->paid = [];
        $this->excluded = array_flip($this->profiles->purchasedExclusions($userId));
        $actor          = $userId ? "u{$userId}" : ($sessionId ? "s{$sessionId}" : 'guest');
        $this->seed     = crc32($actor . ':' . intdiv(time(), self::ROTATION_SECONDS));

        $personal = !$this->profile['is_cold'];
        $history  = $personal ? $this->recentHistory($userId, $sessionId) : ['viewed' => [], 'seen' => [], 'seeds' => []];
        $rows     = [];   // key => ['ids' => [...], 'meta' => [...]]

        // 1. Paid visibility claims first so ads never lose their slot to organic rows.
        $sponsored = $this->sponsoredIds($userId);
        $inject    = [];
        if (count($sponsored) >= $this->min()) {
            $rows['sponsored'] = ['ids' => $this->claim(array_slice($sponsored, 0, $this->row()), 'sponsored', fn () => ['sponsored']), 'meta' => []];
        } elseif ($sponsored) {
            $inject = $this->claim(array_slice($sponsored, 0, count(self::SPONSORED_INJECT_SLOTS)), 'sponsored', fn () => ['sponsored']);
        }
        $this->paid = array_flip(array_merge($rows['sponsored']['ids'] ?? [], $inject));

        if ($personal) {
            $this->addRow($rows, 'recently_viewed', $this->recentlyViewed($history['viewed']));
            if ($userId) {
                $this->addRow($rows, 'favorites', $this->favorites($userId));
            }
            $this->addRow($rows, 'favorite_sellers', ...$this->favoriteSellers($userId));
            $this->addRow($rows, 'recommended', $this->recommended($history['seen']));
            $this->addRow($rows, 'similar', $this->youMightAlsoLike($history['seeds']));
            $this->addRow($rows, 'trending', $this->trending());

            // Thin history → top up with discovery rows instead of showing a near-empty page.
            if (count(array_diff_key($rows, ['sponsored' => 1])) < 3) {
                $this->addRow($rows, 'new_arrivals', $this->newArrivals());
                $this->addRow($rows, 'top_rated', $this->topRated());
            }
        } else {
            $this->addRow($rows, 'trending', $this->trending());
            $this->addRow($rows, 'new_arrivals', $this->newArrivals());
            $this->addRow($rows, 'top_rated', $this->topRated());
            foreach ($this->popularInCategories() as $key => [$ids, $meta]) {
                $this->addRow($rows, $key, $ids, $meta);
            }
        }

        $this->injectSponsored($rows, $inject);

        $feed = [
            'personalized' => $personal,
            'profile_state'=> $personal ? 'warm' : 'cold',
            'sections'     => $this->render($rows),
        ];
        if ($explain) {
            $feed['explain'] = $this->explain;
            $feed['profile'] = $this->profile;
            $feed['excluded_purchased'] = array_keys($this->excluded);
        }
        return $feed;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sections
    // ─────────────────────────────────────────────────────────────────────────

    private function recommended(array $seen): array
    {
        $brands = $this->pool['brands'];
        $scores = $why = [];

        foreach ($this->pool['products'] as $id => $p) {
            $aff = $this->profiles->scoreProduct($this->profile, $p, $brands[$id] ?? null);
            // Needs a real interest match — a price fit alone isn't a recommendation.
            if ($aff['parts']['category'] + $aff['parts']['subcategory'] + $aff['parts']['seller'] + $aff['parts']['brand'] <= 0) {
                continue;
            }
            $pop  = $this->pool['popularity'][$id] ?? 0;
            $qual = $this->quality($id);
            $plan = $this->planScore($p->seller_id);
            $scores[$id] = self::RECOMMENDED_MIX['affinity'] * $aff['score']
                + self::RECOMMENDED_MIX['popularity'] * $pop
                + self::RECOMMENDED_MIX['quality'] * $qual
                + (float) $this->cfg['seller_plan_weight'] * $plan;
            // Already viewed/carted → it's "continue browsing" material, not a discovery.
            if (isset($seen[$id])) {
                $scores[$id] *= self::SEEN_PENALTY;
            }
            $why[$id] = ['affinity' => $aff, 'popularity' => $pop, 'quality' => $qual, 'plan' => $plan, 'already_seen' => isset($seen[$id])];
        }

        $explore = (int) max(1, round($this->row() * (float) $this->cfg['exploration_ratio']));
        $ids = $this->pick($scores, $this->row() - $explore, 0.15);
        if (count($ids) < $this->min()) {
            return [];
        }
        foreach ($ids as $id) {
            $this->note($id, 'recommended', $scores[$id], array_merge($this->topReasons($why[$id]['affinity']['parts']), $why[$id]));
        }

        // Exploration: fresh products the viewer hasn't interacted with, preferring unfamiliar categories.
        $taken   = array_flip($ids);
        $newSince = time() - 86400 * (int) $this->cfg['new_arrival_days'];
        $cand = [];
        foreach ($this->pool['products'] as $id => $p) {
            if (isset($taken[$id]) || isset($seen[$id]) || $p->created_ts < $newSince) {
                continue;
            }
            $familiar = isset($this->profile['categories'][$p->category_id]);
            $cand[$id] = $this->rand($id) * ($familiar ? 0.5 : 1.0);
        }
        $exploreIds = $this->pick($cand, $explore, 0, [], array_flip($ids));
        foreach ($exploreIds as $i => $id) {
            array_splice($ids, min(count($ids), 3 + $i * 5), 0, [$id]);
            $this->note($id, 'recommended', round($cand[$id], 4), ['exploration', 'new_arrival']);
        }

        return $ids;
    }

    private function trending(): array
    {
        $trend = $this->pool['trend'];
        $max   = $trend ? max($trend) : 0;
        $scores = [];
        foreach ($this->pool['products'] as $id => $p) {
            // Real 7-day momentum always ranks above the lifetime-views backfill.
            $scores[$id] = ($trend[$id] ?? 0) > 0
                ? 1 + $trend[$id] / $max
                : 0.99 * ($this->pool['popularity'][$id] ?? 0);
        }
        $ids = $this->pick($scores, $this->row(), 0.10);
        foreach ($ids as $id) {
            $this->note($id, 'trending', round($scores[$id], 4), [($trend[$id] ?? 0) > 0 ? 'trending_7d' : 'popular_all_time', 'trend_score' => $trend[$id] ?? 0]);
        }
        return $ids;
    }

    private function youMightAlsoLike(array $seeds): array
    {
        $result = $this->similar->similarTo($seeds, 80);
        $scores = [];
        foreach ($result['scores'] as $id => $sim) {
            if (!isset($this->pool['products'][$id])) {
                continue;
            }
            $aff = $this->profiles->scoreProduct($this->profile, $this->pool['products'][$id], $this->pool['brands'][$id] ?? null);
            $scores[$id] = 0.8 * $sim + 0.2 * $aff['score'];
        }
        $ids = $this->pick($scores, $this->row(), 0.10);
        foreach ($ids as $id) {
            $this->note($id, 'similar', round($scores[$id], 4), [
                'similar_to' => $result['seeds_of'][$id] ?? null,
                'similarity' => $result['scores'][$id],
                'source'     => $result['source'],
            ]);
        }
        return $ids;
    }

    private function favorites(int $userId): array
    {
        $ids = DB::table('favorites')->where('user_id', $userId)
            ->orderByDesc('created_at')->pluck('product_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
        $ids = $this->pools->eligibleIds($ids);
        $ids = array_slice(array_values(array_filter($ids, fn ($id) => !isset($this->claimed[$id]))), 0, $this->row());
        foreach ($ids as $id) {
            $this->note($id, 'favorites', 1, ['in_your_favourites']);
        }
        return $ids;
    }

    /** @return array{0: int[], 1: array} ids, meta */
    private function favoriteSellers(?int $userId): array
    {
        if (!$userId) {
            return [[], []];
        }
        // "Favourite" = followed, or bought from in 2+ separate (non-cancelled) orders.
        // Browsing a shop a few times isn't enough — that's what "recommended" is for.
        $aff = $this->profile['sellers'] ?? [];
        $followed = $this->profile['followed_seller_ids'] ?? [];
        $sellers = [];
        foreach ($followed as $sid) {
            $sellers[$sid] = max(0.8, $aff[$sid] ?? 0);
        }
        foreach ($this->repeatSellers($userId) as $sid => $orders) {
            $sellers[$sid] = max($sellers[$sid] ?? 0, min(1.0, 0.4 + 0.1 * $orders), $aff[$sid] ?? 0);
        }
        arsort($sellers);
        $sellers = array_slice($sellers, 0, 6, true);
        if (!$sellers) {
            return [[], []];
        }

        $newSince = 86400 * max(1, (int) $this->cfg['new_arrival_days']);
        $scores = [];
        foreach ($this->pool['products'] as $id => $p) {
            if (!isset($sellers[$p->seller_id])) {
                continue;
            }
            $newness = exp(-max(0, time() - $p->created_ts) / $newSince);
            $scores[$id] = 0.35 * $sellers[$p->seller_id] + 0.25 * $newness
                + 0.20 * ($this->pool['popularity'][$id] ?? 0)
                + 0.20 * $this->profiles->scoreProduct($this->profile, $p, $this->pool['brands'][$id] ?? null)['score'];
        }
        $ids = $this->pick($scores, $this->row(), 0.10, ['seller' => 6, 'category' => 6]);
        foreach ($ids as $id) {
            $sid = $this->pool['products'][$id]->seller_id;
            $this->note($id, 'favorite_sellers', round($scores[$id], 4), [in_array($sid, $followed, true) ? 'followed_seller' : 'frequent_seller', 'seller_id' => $sid]);
        }

        $shown = array_values(array_unique(array_map(fn ($id) => $this->pool['products'][$id]->seller_id, $ids)));
        return [$ids, ['sellers' => $this->sellerBadges($shown, $followed)]];
    }

    /** seller_id => number of distinct non-cancelled orders (last 180 days), only sellers with 2+. */
    private function repeatSellers(int $userId): array
    {
        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->leftJoin('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->where('o.user_id', $userId)
            ->where('o.created_at', '>=', now()->subDays(180))
            ->whereNotIn('o.status', ['cancelled', 'refunded'])
            ->where(fn ($q) => $q->whereNull('so.status')->orWhereNotIn('so.status', ['cancelled', 'refunded']))
            ->whereNotNull('p.seller_id')
            ->groupBy('p.seller_id')
            ->havingRaw('COUNT(DISTINCT o.id) >= 2')
            ->get(['p.seller_id', DB::raw('COUNT(DISTINCT o.id) as orders')])
            ->mapWithKeys(fn ($r) => [(int) $r->seller_id => (int) $r->orders])
            ->all();
    }

    private function recentlyViewed(array $viewed): array
    {
        $ids = array_values(array_filter(
            $this->pools->eligibleIds($viewed),
            fn ($id) => !isset($this->excluded[$id]) && !isset($this->claimed[$id])
        ));
        $ids = array_slice($ids, 0, $this->row());
        foreach ($ids as $i => $id) {
            $this->note($id, 'recently_viewed', $this->row() - $i, ['recently_viewed']);
        }
        return $ids;
    }

    private function newArrivals(): array
    {
        $scores = array_map(fn ($p) => (float) $p->created_ts, $this->pool['products']);
        $ids = $this->pick($scores, $this->row(), 0);
        foreach ($ids as $id) {
            $this->note($id, 'new_arrivals', 1, ['new_arrival', 'created_at' => date('Y-m-d', $this->pool['products'][$id]->created_ts)]);
        }
        return $ids;
    }

    private function topRated(): array
    {
        $scores = [];
        foreach ($this->pool['rating'] as $id => $r) {
            if (isset($this->pool['products'][$id]) && $r['bayes'] >= 3.5) {
                $scores[$id] = $r['bayes'];
            }
        }
        $ids = $this->pick($scores, $this->row(), 0.03);
        foreach ($ids as $id) {
            $this->note($id, 'top_rated', $scores[$id], ['top_rated', 'rating' => $this->pool['rating'][$id]]);
        }
        return $ids;
    }

    /** @return array<string, array{0: int[], 1: array}> */
    private function popularInCategories(): array
    {
        $byCat = [];
        foreach ($this->pool['products'] as $id => $p) {
            if ($p->category_id && isset($this->pool['categories'][$p->category_id])) {
                $byCat[$p->category_id][$id] = $this->pool['popularity'][$id] ?? 0;
            }
        }
        uasort($byCat, fn ($a, $b) => array_sum($b) <=> array_sum($a));

        $out = [];
        foreach ($byCat as $catId => $scores) {
            if (count($out) >= self::POPULAR_CATEGORY_ROWS) {
                break;
            }
            $ids = $this->pick($scores, $this->row(), 0.10, ['category' => PHP_INT_MAX]);
            if (count($ids) < $this->min()) {
                continue;
            }
            $key = "popular_in_category:{$catId}";
            foreach ($ids as $id) {
                $this->note($id, $key, round($scores[$id], 4), ['popular_in_category']);
            }
            $c = $this->pool['categories'][$catId];
            $out[$key] = [$ids, ['category' => ['id' => (int) $c->id, 'slug' => $c->slug, 'name' => $c->name, 'name_fr' => $c->name_fr, 'name_ar' => $c->name_ar]]];
        }
        return $out;
    }

    /** Active sponsored products the viewer is targeted by, highest priority first. */
    private function sponsoredIds(?int $userId): array
    {
        try {
            Sponsorship::expireOverdue();
        } catch (\Throwable $e) {
        }

        $candidates = array_keys(array_filter(
            $this->pool['products'],
            fn ($p, $id) => $p->is_sponsored && !isset($this->excluded[$id]),
            ARRAY_FILTER_USE_BOTH
        ));
        if (!$candidates) {
            return [];
        }

        try {
            $user  = $userId ? User::find($userId) : null;
            $prefs = $userId ? UserPreference::where('user_id', $userId)->first() : null;

            return Product::whereIn('id', $candidates)
                ->with(['sponsorships' => fn ($q) => $q->where('status', 'active')])
                ->orderByDesc('sponsored_priority')->orderByDesc('sponsored_at')
                ->get(['id', 'sponsored_priority', 'sponsored_at'])
                ->filter(fn ($p) => ($s = $p->sponsorships->first()) && $s->matchesUser($user, $prefs))
                ->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
        } catch (\Throwable $e) {
            Log::warning('[HomeFeed] sponsored lookup failed: ' . $e->getMessage());
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // History (for recently viewed, exploration and similarity seeds)
    // ─────────────────────────────────────────────────────────────────────────

    private function recentHistory(?int $userId, ?string $sessionId): array
    {
        $q = DB::table('user_interactions')
            ->whereNotNull('product_id')
            ->where('created_at', '>=', now()->subDays(60))
            ->whereIn('event_type', ['view', 'click', 'cart_add', 'favorite_add', 'purchase'])
            ->orderByDesc('created_at')
            ->limit(300);
        $userId ? $q->where('user_id', $userId) : $q->where('session_id', $sessionId)->whereNull('user_id');

        $weights = config('recommendations.weights');
        $viewed = $seen = $seeds = [];
        $now = time();
        foreach ($q->get(['product_id', 'event_type', 'created_at']) as $e) {
            $id = (int) $e->product_id;
            $seen[$id] = true;
            if (in_array($e->event_type, ['view', 'click'], true) && !in_array($id, $viewed, true)) {
                $viewed[] = $id;
            }
            // Seeds favour what happened *recently* (7-day half-life), not all-time taste.
            $age = max(0, $now - strtotime($e->created_at)) / 86400;
            $seeds[$id] = ($seeds[$id] ?? 0) + ($weights[$e->event_type] ?? 1) * pow(2, -$age / 7);
        }

        arsort($seeds);
        $seeds = array_slice($seeds, 0, 5, true);
        $max = $seeds ? max($seeds) : 0;

        return [
            'viewed' => array_slice($viewed, 0, 30),
            'seen'   => $seen,
            'seeds'  => $max > 0 ? array_map(fn ($v) => round($v / $max, 4), $seeds) : [],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Selection helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Rank by score with seeded jitter, skipping claimed/excluded products, then fill greedily
     * under per-seller / per-category caps. If the row is still short, the category cap is
     * doubled once; the seller cap is never relaxed (no row dominated by one shop).
     */
    private function pick(array $scores, int $n, float $rotation, array $caps = [], array $skip = []): array
    {
        if ($n <= 0) {
            return [];
        }
        $adj = [];
        foreach ($scores as $id => $s) {
            if (isset($this->claimed[$id]) || isset($this->excluded[$id]) || isset($skip[$id]) || !isset($this->pool['products'][$id])) {
                continue;
            }
            $adj[$id] = $rotation > 0 ? $s * (1 + $rotation * (2 * $this->rand($id) - 1)) : $s;
        }
        arsort($adj);

        $maxSeller = $caps['seller']   ?? (int) $this->cfg['max_per_seller'];
        $maxCat    = $caps['category'] ?? (int) $this->cfg['max_per_category'];

        // A cap the candidates can't satisfy would just empty the row (e.g. a catalog where one
        // shop owns everything). Never cap below what the available variety can fill.
        $sellers = $cats = [];
        foreach ($adj as $id => $_) {
            $sellers[$this->pool['products'][$id]->seller_id ?? 0] = true;
            $cats[$this->pool['products'][$id]->category_id ?? 0]  = true;
        }
        $maxSeller = max($maxSeller, (int) ceil($n / max(1, count($sellers))));
        $maxCat    = max($maxCat, (int) ceil($n / max(1, count($cats))));
        $out = [];
        foreach ([1, 2] as $relax) {
            $bySeller = $byCat = [];
            // $skip = products already in this row: never re-picked, but they count toward the caps.
            foreach (array_merge($out, array_keys($skip)) as $id) {
                if (!isset($this->pool['products'][$id])) {
                    continue;
                }
                $p = $this->pool['products'][$id];
                $bySeller[$p->seller_id ?? 0] = ($bySeller[$p->seller_id ?? 0] ?? 0) + 1;
                $byCat[$p->category_id ?? 0]  = ($byCat[$p->category_id ?? 0] ?? 0) + 1;
            }
            $taken = array_flip($out);
            foreach ($adj as $id => $_) {
                if (count($out) >= $n) {
                    return $out;
                }
                if (isset($taken[$id])) {
                    continue;
                }
                $p = $this->pool['products'][$id];
                $s = $p->seller_id ?? 0;
                $c = $p->category_id ?? 0;
                if (($bySeller[$s] ?? 0) >= $maxSeller || ($byCat[$c] ?? 0) >= $maxCat * $relax) {
                    continue;
                }
                $out[] = $id;
                $bySeller[$s] = ($bySeller[$s] ?? 0) + 1;
                $byCat[$c]    = ($byCat[$c] ?? 0) + 1;
            }
        }
        return $out;
    }

    private function addRow(array &$rows, string $key, array $ids, array $meta = []): void
    {
        if (count($ids) < $this->min()) {
            // Too weak to show: release any notes so the products can appear elsewhere.
            foreach ($ids as $id) {
                if (($this->explain[$id]['section'] ?? null) === $key) {
                    unset($this->explain[$id]);
                }
            }
            return;
        }
        $rows[$key] = ['ids' => $this->claim($ids, $key), 'meta' => $meta];
    }

    private function claim(array $ids, string $section, ?callable $why = null): array
    {
        foreach ($ids as $id) {
            $this->claimed[$id] = $section;
            if ($why && !isset($this->explain[$id])) {
                $this->note($id, $section, 1, $why($id));
            }
        }
        return $ids;
    }

    /** Sponsored products that couldn't fill their own row ride in the first organic row, labelled. */
    private function injectSponsored(array &$rows, array $inject): void
    {
        if (!$inject) {
            return;
        }
        foreach (['recommended', 'trending', 'new_arrivals'] as $host) {
            if (isset($rows[$host])) {
                foreach (array_values($inject) as $i => $id) {
                    array_splice($rows[$host]['ids'], min(count($rows[$host]['ids']), self::SPONSORED_INJECT_SLOTS[$i] ?? 1), 0, [$id]);
                    $this->note($id, $host, 1, ['sponsored', 'injected_into' => $host]);
                }
                return;
            }
        }
        // Nothing organic to ride in: show them as their own (short) sponsored row.
        $rows['sponsored'] = ['ids' => $inject, 'meta' => []];
    }

    private function render(array $rows): array
    {
        $allIds = array_merge(...array_values(array_map(fn ($r) => $r['ids'], $rows ?: [['ids' => []]])));
        $cards  = $this->presenter->cardsFor($allIds);

        $ordered = [];
        foreach (self::DISPLAY_ORDER as $type) {
            foreach ($rows as $key => $row) {
                if ($key !== $type && !str_starts_with($key, $type . ':')) {
                    continue;
                }
                $products = [];
                foreach ($row['ids'] as $id) {
                    if (!isset($cards[$id])) {
                        continue;
                    }
                    $card = $cards[$id];
                    // Only paid placements carry the Sponsored label.
                    $isPaid = isset($this->paid[$id]);
                    $card['is_sponsored'] = $isPaid;
                    $card['placement']    = $isPaid ? 'sponsored' : 'organic';
                    if (!$isPaid) {
                        unset($card['sponsor_data']);
                    }
                    $products[] = $card;
                }
                $ordered[] = ['key' => $key, 'type' => $type, 'meta' => (object) $row['meta'], 'products' => $products];
            }
        }
        return $ordered;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Small helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function quality(int $id): float
    {
        $r = $this->pool['rating'][$id] ?? null;
        $rating = $r ? max(0, min(1, ($r['bayes'] - 3) / 2)) : 0.4;
        $age = max(0, time() - ($this->pool['products'][$id]->created_ts ?? 0)) / 86400;
        return round(0.6 * $rating + 0.4 * exp(-$age / 30), 4);
    }

    private function planScore(?int $sellerId): float
    {
        return match ($this->pool['plans'][$sellerId ?? 0] ?? 'free') {
            'black' => 1.0,
            'red'   => 0.6,
            default => 0.2,
        };
    }

    /** Deterministic 0..1 per (viewer, 5-minute window, product). */
    private function rand(int $id): float
    {
        return crc32($this->seed . ':' . $id) / 4294967295;
    }

    private function topReasons(array $parts): array
    {
        arsort($parts);
        $labels = [];
        foreach ($parts as $dim => $v) {
            if ($v > 0 && count($labels) < 2) {
                $labels[] = "{$dim}_affinity";
            }
        }
        return $labels;
    }

    private function note(int $id, string $section, $score, array $why): void
    {
        $this->explain[$id] = ['section' => $section, 'score' => $score, 'why' => $why];
    }

    private function sellerBadges(array $sellerIds, array $followed): array
    {
        if (!$sellerIds) {
            return [];
        }
        return User::whereIn('id', $sellerIds)->get(['id', 'name', 'avatar'])
            ->map(fn ($u) => array_merge(['id' => $u->id], array_intersect_key($u->storefrontBranding(), ['business_name' => 1, 'avatar' => 1]), [
                'plan'     => $this->pool['plans'][$u->id] ?? 'free',
                'followed' => in_array($u->id, $followed, true),
            ]))->values()->all();
    }

    private function row(): int
    {
        return (int) $this->cfg['row_size'];
    }

    private function min(): int
    {
        return (int) $this->cfg['min_section_size'];
    }
}
