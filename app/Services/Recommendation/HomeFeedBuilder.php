<?php

namespace App\Services\Recommendation;

use App\Models\Product;
use App\Models\Sponsorship;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Assembles the personalized homepage: every section in one response.
 *
 * CORE rows always appear (topped up from popular/new products when short):
 *   warm (has activity):  recommended, trending, best_sellers, new_arrivals
 *   cold (guest/new):     trending, best_sellers, new_arrivals, top_rated
 * OPTIONAL rows appear only with enough data: sponsored, similar, favorites,
 *   favorite_sellers, recently_viewed, top_rated (warm), popular_in_category (cold).
 *
 * Deduplication uses an appearance budget. Standard catalogs: a product appears
 * once on the whole page (including Flash deals / Brand collection, which are
 * reserved up front). Small catalogs (< small_catalog.max_products products or
 * < min_sellers sellers): up to max_appearances rows, unique products always
 * picked before repeats, and the per-seller cap is lifted — otherwise a launch
 * catalog would render an empty page.
 *
 * Claim order: the viewer's own lists (recently viewed, favourites) → paid → recommended
 * → favourite sellers → similar → trending / best sellers / new arrivals → top rated.
 * On small catalogs favourite sellers picks before recommended and similar after the
 * core rows, so nothing narrow or core gets starved. Display order differs.
 *
 * Diversity: per-row caps per seller / category, ~15% exploration slots in
 * "recommended", and a seeded ±jitter that changes every 5 minutes so the page
 * rotates between visits but stays stable while browsing.
 */
class HomeFeedBuilder
{
    const DISPLAY_ORDER = [
        'recommended', 'sponsored', 'trending', 'best_sellers', 'similar', 'favorites',
        'favorite_sellers', 'recently_viewed', 'new_arrivals', 'top_rated', 'popular_in_category',
    ];

    /** Recommended = personal affinity + a little popularity/quality + seller plan as a tie-breaker. */
    const RECOMMENDED_MIX = ['affinity' => 0.70, 'popularity' => 0.15, 'quality' => 0.10];

    const SEEN_PENALTY = 0.3;
    const ROTATION_SECONDS = 300;
    const POPULAR_CATEGORY_ROWS = 3;
    const SPONSORED_INJECT_SLOTS = [1, 5];
    const CORE_ROWS = 4;
    const MIN_ROW = 6;
    const MIN_OWN_LIST = 2;   // favourites / recently viewed

    private array $cfg;
    private array $pool;
    private array $profile;
    private array $uses = [];       // product_id => rows it's already in (incl. reserved)
    private array $excluded = [];   // bought, never shown
    private array $notes = [];      // section => product_id => note
    private array $paidIn = [];     // "section:product_id" => true
    private bool $small = false;
    private int $maxUses = 1;
    private int $rowTarget = 16;
    private int $seed = 0;
    private ?array $backfill = null;

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
        $this->uses     = $this->notes = $this->paidIn = [];
        $this->backfill = null;
        $this->excluded = array_flip($this->profiles->purchasedExclusions($userId));
        $actor          = $userId ? "u{$userId}" : ($sessionId ? "s{$sessionId}" : 'guest');
        $this->seed     = crc32($actor . ':' . intdiv(time(), self::ROTATION_SECONDS));

        $small = $this->cfg['small_catalog'];
        $this->small   = count($this->pool['products']) < (int) $small['max_products']
                      || ($this->pool['sellers'] ?? 0) < (int) $small['min_sellers'];
        $this->maxUses = $this->small ? max(1, (int) $small['max_appearances']) : 1;

        // Flash deals and the Brand collection already show these on the homepage.
        foreach ($this->pool['reserved'] ?? [] as $id => $_) {
            $this->uses[$id] = 1;
        }

        $personal = !$this->profile['is_cold'];
        $this->sizeRows($personal);
        $history  = $personal ? $this->recentHistory($userId, $sessionId) : ['viewed' => [], 'seen' => [], 'seeds' => []];
        $rows     = [];   // key => ['ids' => [...], 'meta' => [...]]

        // The viewer's own lists come first: they're their data, not recommendations,
        // and they show even when short (a favourite that is also sponsored stays here).
        if ($personal) {
            $this->optionalRow($rows, 'recently_viewed', $this->recentlyViewed($history['viewed']), [], self::MIN_OWN_LIST);
            if ($userId) {
                $this->optionalRow($rows, 'favorites', $this->favorites($userId), [], self::MIN_OWN_LIST);
            }
        }

        // Paid visibility next, so ads never lose their slot to organic rows.
        $sponsored = $this->sponsoredIds($userId);
        $inject    = [];
        if (count($sponsored) >= (int) $this->cfg['min_section_size']) {
            $ids = array_slice($sponsored, 0, $this->row());
            foreach ($ids as $id) {
                $this->note('sponsored', $id, 1, ['sponsored']);
                $this->paidIn["sponsored:{$id}"] = true;
            }
            $rows['sponsored'] = ['ids' => $this->claim($ids), 'meta' => []];
        } elseif ($sponsored) {
            $inject = $this->claim(array_slice($sponsored, 0, count(self::SPONSORED_INJECT_SLOTS)));
        }

        if ($personal) {
            // Standard catalog: Recommended picks first (its per-seller cap leaves the favourite
            // sellers plenty). Small catalog: the narrow seller row picks first so it isn't starved.
            if (!$this->small) {
                $this->coreRow($rows, 'recommended', $this->recommended($history['seen']));
            }
            [$ids, $meta] = $this->favoriteSellers($userId);
            $this->optionalRow($rows, 'favorite_sellers', $ids, $meta);
            if ($this->small) {
                $this->coreRow($rows, 'recommended', $this->recommended($history['seen']));
            }
            // Standard catalog: "similar" is personal, so it picks before the generic core rows.
            // Small catalog: it goes last so it can never starve a core row.
            if (!$this->small) {
                $this->optionalRow($rows, 'similar', $this->youMightAlsoLike($history['seeds']));
            }
            $this->coreRow($rows, 'trending', $this->trending());
            $this->coreRow($rows, 'best_sellers', $this->bestSellers());
            $this->coreRow($rows, 'new_arrivals', $this->newArrivals());
            if ($this->small) {
                $this->optionalRow($rows, 'similar', $this->youMightAlsoLike($history['seeds']));
            }
            $this->optionalRow($rows, 'top_rated', $this->topRated());
        } else {
            $this->coreRow($rows, 'trending', $this->trending());
            $this->coreRow($rows, 'best_sellers', $this->bestSellers());
            $this->coreRow($rows, 'new_arrivals', $this->newArrivals());
            $this->coreRow($rows, 'top_rated', $this->topRated());
            foreach ($this->popularInCategories() as $key => [$ids, $meta]) {
                $this->optionalRow($rows, $key, $ids, $meta);
            }
        }

        $this->injectSponsored($rows, $inject);

        $feed = [
            'personalized'  => $personal,
            'profile_state' => $personal ? 'warm' : 'cold',
            'catalog_mode'  => $this->small ? 'small' : 'standard',
            'sections'      => $this->render($rows),
        ];
        if ($explain) {
            $feed['explain'] = $this->notes;
            $feed['profile'] = $this->profile;
            $feed['excluded_purchased'] = array_keys($this->excluded);
            $feed['reserved_by_other_rows'] = $this->pool['reserved'] ?? [];
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
        foreach ($ids as $id) {
            $this->note('recommended', $id, $scores[$id], array_merge($this->topReasons($why[$id]['affinity']['parts']), $why[$id]));
        }
        if (!$ids) {
            return [];
        }

        // Exploration: fresh products the viewer hasn't interacted with, preferring unfamiliar categories.
        $newSince = time() - 86400 * (int) $this->cfg['new_arrival_days'];
        $cand = [];
        foreach ($this->pool['products'] as $id => $p) {
            if (isset($seen[$id]) || $p->created_ts < $newSince) {
                continue;
            }
            $familiar = isset($this->profile['categories'][$p->category_id]);
            $cand[$id] = $this->rand($id) * ($familiar ? 0.5 : 1.0);
        }
        $exploreIds = $this->pick($cand, $explore, 0, [], array_flip($ids));
        foreach ($exploreIds as $i => $id) {
            array_splice($ids, min(count($ids), 3 + $i * 5), 0, [$id]);
            $this->note('recommended', $id, round($cand[$id], 4), ['exploration', 'new_arrival']);
        }

        return $ids;
    }

    private function trending(): array
    {
        $trend = $this->pool['trend'];
        $max   = $trend ? max($trend) : 0;
        $scores = [];
        foreach ($this->pool['products'] as $id => $p) {
            // Real 7-day momentum always ranks above the lifetime-views fallback.
            $scores[$id] = ($trend[$id] ?? 0) > 0
                ? 1 + $trend[$id] / $max
                : 0.99 * ($this->pool['popularity'][$id] ?? 0);
        }
        $ids = $this->pick($scores, $this->row(), 0.10);
        foreach ($ids as $id) {
            $this->note('trending', $id, round($scores[$id], 4), [($trend[$id] ?? 0) > 0 ? 'trending_7d' : 'popular_all_time', 'trend_score' => $trend[$id] ?? 0]);
        }
        return $ids;
    }

    private function bestSellers(): array
    {
        $scores = [];
        foreach ($this->pool['sales'] ?? [] as $id => $units) {
            $scores[$id] = log1p($units);
        }
        $ids = $this->pick($scores, $this->row(), 0.05);
        foreach ($ids as $id) {
            $this->note('best_sellers', $id, round($scores[$id], 4), ['best_seller', 'units_sold' => $this->pool['sales'][$id]]);
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
            $this->note('similar', $id, round($scores[$id], 4), [
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
        $ids = array_slice(array_values(array_filter($this->pools->eligibleIds($ids), fn ($id) => $this->canUse($id, true))), 0, $this->row());
        foreach ($ids as $id) {
            $this->note('favorites', $id, 1, ['in_your_favourites']);
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
            $this->note('favorite_sellers', $id, round($scores[$id], 4), [in_array($sid, $followed, true) ? 'followed_seller' : 'frequent_seller', 'seller_id' => $sid]);
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
        $ids = array_values(array_filter($this->pools->eligibleIds($viewed), fn ($id) => $this->canUse($id)));
        $ids = array_slice($ids, 0, $this->row());
        foreach ($ids as $i => $id) {
            $this->note('recently_viewed', $id, $this->row() - $i, ['recently_viewed']);
        }
        return $ids;
    }

    private function newArrivals(): array
    {
        $scores = array_map(fn ($p) => (float) $p->created_ts, $this->pool['products']);
        $ids = $this->pick($scores, $this->row(), 0);
        foreach ($ids as $id) {
            $this->note('new_arrivals', $id, 1, ['new_arrival', 'created_at' => date('Y-m-d', $this->pool['products'][$id]->created_ts)]);
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
            $this->note('top_rated', $id, $scores[$id], ['top_rated', 'rating' => $this->pool['rating'][$id]]);
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
            $key = "popular_in_category:{$catId}";
            $ids = $this->pick($scores, $this->row(), 0.10, ['category' => PHP_INT_MAX]);
            if (count($ids) < $this->minOptional()) {
                continue;
            }
            foreach ($ids as $id) {
                $this->note($key, $id, round($scores[$id], 4), ['popular_in_category']);
            }
            $c = $this->pool['categories'][$catId];
            $out[$key] = [$ids, ['category' => ['id' => (int) $c->id, 'slug' => $c->slug, 'name' => $c->name, 'name_fr' => $c->name_fr, 'name_ar' => $c->name_ar]]];
        }
        return $out;
    }

    /** Active sponsored products the viewer is targeted by, highest priority first. */
    private function sponsoredIds(?int $userId): array
    {
        $candidates = array_keys(array_filter(
            $this->pool['products'],
            fn ($p, $id) => $p->is_sponsored && !isset($this->excluded[$id]) && $this->canUse($id),
            ARRAY_FILTER_USE_BOTH
        ));
        if (!$candidates) {
            return [];
        }

        try {
            $user  = $userId ? User::find($userId) : null;
            $prefs = $userId ? UserPreference::where('user_id', $userId)->first() : null;

            return Product::whereIn('id', $candidates)
                // live(): ended rows drop out even before ads:complete-ended flips them
                ->with(['sponsorships' => fn ($q) => $q->live()])
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
     * Share the available appearances between the core rows (+1 spare, +2 for a warm
     * viewer's personal rows) so the first rows can't swallow a modest catalog.
     * 80 products → 16 per row; 14 products on a small catalog → 6.
     */
    private function sizeRows(bool $personal): void
    {
        $rows = self::CORE_ROWS + 1 + ($personal ? 2 : 0);
        $this->rowTarget = (int) max(self::MIN_ROW, min(
            (int) $this->cfg['row_size'],
            floor(count($this->pool['products']) * $this->maxUses / $rows)
        ));
    }

    /** Can this product go into one more row? ($ignoreExclusion: the viewer's own favourites.) */
    private function canUse(int $id, bool $ignoreExclusion = false): bool
    {
        return ($ignoreExclusion || !isset($this->excluded[$id])) && ($this->uses[$id] ?? 0) < $this->maxUses;
    }

    /**
     * Rank by score with seeded jitter, then fill greedily under per-seller / per-category
     * caps: first with products not yet on the page, then (small catalogs only) with ones
     * shown once. Within each tier the category cap is doubled once if the row is short.
     * The seller cap is never relaxed on a standard catalog, and never applied on a small one.
     */
    private function pick(array $scores, int $n, float $rotation, array $caps = [], array $skip = []): array
    {
        if ($n <= 0) {
            return [];
        }
        $adj = [];
        foreach ($scores as $id => $s) {
            if (isset($skip[$id]) || !isset($this->pool['products'][$id]) || !$this->canUse($id)) {
                continue;
            }
            $adj[$id] = $rotation > 0 ? $s * (1 + $rotation * (2 * $this->rand($id) - 1)) : $s;
        }
        if (!$adj) {
            return [];
        }
        arsort($adj);

        // A cap the candidates can't satisfy would just empty the row. Never cap below what
        // the available variety can fill; on small catalogs drop the seller cap entirely.
        $sellers = $cats = [];
        foreach ($adj as $id => $_) {
            $sellers[$this->pool['products'][$id]->seller_id ?? 0] = true;
            $cats[$this->pool['products'][$id]->category_id ?? 0]  = true;
        }
        $maxSeller = $this->small ? PHP_INT_MAX
            : max($caps['seller'] ?? (int) $this->cfg['max_per_seller'], (int) ceil($n / count($sellers)));
        $maxCat = max($caps['category'] ?? (int) $this->cfg['max_per_category'], (int) ceil($n / count($cats)));

        $bySeller = $byCat = [];
        foreach (array_keys($skip) as $id) {   // already in this row: count toward the caps
            if (isset($this->pool['products'][$id])) {
                $p = $this->pool['products'][$id];
                $bySeller[$p->seller_id ?? 0] = ($bySeller[$p->seller_id ?? 0] ?? 0) + 1;
                $byCat[$p->category_id ?? 0]  = ($byCat[$p->category_id ?? 0] ?? 0) + 1;
            }
        }

        $tiers = [array_filter($adj, fn ($id) => ($this->uses[$id] ?? 0) === 0, ARRAY_FILTER_USE_KEY)];
        if ($this->maxUses > 1) {
            $tiers[] = array_filter($adj, fn ($id) => ($this->uses[$id] ?? 0) > 0, ARRAY_FILTER_USE_KEY);
        }

        $out = [];
        $taken = [];
        foreach ($tiers as $tier) {
            foreach ([1, 2] as $relax) {
                foreach ($tier as $id => $_) {
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
                    $taken[$id] = true;
                    $bySeller[$s] = ($bySeller[$s] ?? 0) + 1;
                    $byCat[$c]    = ($byCat[$c] ?? 0) + 1;
                }
            }
        }
        return $out;
    }

    /** Core rows always show: when short, they're topped up with popular + new products. */
    private function coreRow(array &$rows, string $key, array $ids, array $meta = []): void
    {
        if (count($ids) < $this->row()) {
            $scores = $this->backfillScores();
            $fill = $this->pick($scores, $this->row() - count($ids), 0.10, [], array_flip($ids));
            foreach ($fill as $id) {
                $this->note($key, $id, round($scores[$id], 4), ['backfill_popular_new']);
            }
            $ids = array_merge($ids, $fill);
        }
        if ($ids) {
            $rows[$key] = ['ids' => $this->claim($ids), 'meta' => $meta];
        } else {
            unset($this->notes[$key]);
        }
    }

    /** Optional rows (personal, similar, …) are hidden when too thin. */
    private function optionalRow(array &$rows, string $key, array $ids, array $meta = [], ?int $min = null): void
    {
        if (count($ids) < ($min ?? $this->minOptional())) {
            unset($this->notes[$key]);
            return;
        }
        $rows[$key] = ['ids' => $this->claim($ids), 'meta' => $meta];
    }

    /** Popular, with a boost for recent listings: what a core row shows when its own signal runs out. */
    private function backfillScores(): array
    {
        if ($this->backfill === null) {
            $this->backfill = [];
            foreach ($this->pool['products'] as $id => $p) {
                $newness = exp(-max(0, time() - $p->created_ts) / (86400 * 30));
                $this->backfill[$id] = 0.6 * ($this->pool['popularity'][$id] ?? 0) + 0.4 * $newness;
            }
        }
        return $this->backfill;
    }

    private function claim(array $ids): array
    {
        foreach ($ids as $id) {
            $this->uses[$id] = ($this->uses[$id] ?? 0) + 1;
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
                    $this->note($host, $id, 1, ['sponsored', 'injected_into' => $host]);
                    $this->paidIn["{$host}:{$id}"] = true;
                }
                return;
            }
        }
        // Nothing organic to ride in: show them as their own (short) sponsored row.
        foreach ($inject as $id) {
            $this->note('sponsored', $id, 1, ['sponsored']);
            $this->paidIn["sponsored:{$id}"] = true;
        }
        $rows['sponsored'] = ['ids' => $inject, 'meta' => []];
    }

    private function render(array $rows): array
    {
        $allIds = array_values(array_unique(array_merge(...array_values(array_map(fn ($r) => $r['ids'], $rows ?: [['ids' => []]])))));
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
                    $isPaid = isset($this->paidIn["{$key}:{$id}"]);
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

    private function note(string $section, int $id, $score, array $why): void
    {
        $this->notes[$section][$id] = ['score' => $score, 'why' => $why];
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
        return $this->rowTarget;
    }

    private function minOptional(): int
    {
        return $this->small
            ? (int) $this->cfg['small_catalog']['min_section_size']
            : (int) $this->cfg['min_section_size'];
    }
}
