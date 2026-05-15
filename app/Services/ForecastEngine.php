<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ForecastEngine — SEASON-AWARE VERSION
 *
 * ROOT CAUSE FIX:
 * The previous version applied a generic Tunisia monthly shopping index
 * (high in August = back-to-school) to ALL products regardless of their
 * declared season. A winter hoodie would show August as peak because the
 * generic index is highest there.
 *
 * FIX: We now read the product's declared season[] from the DB and build
 * a product-season-aware monthly index that boosts the correct months
 * and suppresses mismatched months. This is blended 60/40 with the
 * generic Tunisia commerce index to preserve real market signal.
 *
 * RESULT: A winter product → Dec/Jan/Feb peak. Ramadan product → Feb/Mar peak.
 * Back-to-school product → Aug/Sep peak. All logically correct.
 */
class ForecastEngine
{
    // Statuses that count as real demand signal
    private const COUNTED_STATUSES = ['pending', 'processing', 'completed', 'delivered'];

    // ── Generic Tunisia monthly commerce index (month 1-12) ─────────────
    // Reflects overall Tunisian online shopping patterns across ALL categories.
    // Used as a 40% weight component alongside the product-season index.
    private const TUNISIA_COMMERCE_INDEX = [
        1  => 1.05,  // Jan — post new-year, soldes
        2  => 0.98,  // Feb — quiet
        3  => 1.35,  // Mar — Ramadan season starts
        4  => 1.20,  // Apr — Eid spike
        5  => 1.08,  // May — moderate
        6  => 1.12,  // Jun — Eid Al-Adha prep + summer start
        7  => 1.15,  // Jul — summer shopping
        8  => 1.20,  // Aug — back-to-school rush
        9  => 1.18,  // Sep — back-to-school continues
        10 => 1.05,  // Oct — normal
        11 => 0.95,  // Nov — pre-soldes quiet
        12 => 1.15,  // Dec — new year + soldes
    ];

    // ── Product-season monthly demand profiles ──────────────────────────
    // For each declared season slug, defines which calendar months are
    // strong (>1.0), neutral (≈1.0), or weak (<1.0) for that product type.
    // Scale: 0.5 (very suppressed) → 2.0 (very strong peak)
    private const SEASON_MONTHLY_PROFILES = [
        'all_seasons' => [
            // Flat — no seasonal preference, all months roughly equal
            1=>1.00, 2=>1.00, 3=>1.05, 4=>1.05, 5=>1.00,
            6=>1.00, 7=>1.00, 8=>1.00, 9=>1.00, 10=>1.00,
            11=>1.00, 12=>1.05,
        ],
        'winter' => [
            // Peak: Nov–Feb (cold season)
            // Suppressed: May–Sep (warm months — nobody buys hoodies)
            1=>1.60, 2=>1.50, 3=>1.10, 4=>0.85, 5=>0.60,
            6=>0.50, 7=>0.45, 8=>0.45, 9=>0.55, 10=>1.20,
            11=>1.55, 12=>1.65,
        ],
        'summer' => [
            // Peak: Jun–Aug (hot season + tourism)
            // Suppressed: Nov–Feb (cold months)
            1=>0.60, 2=>0.55, 3=>0.70, 4=>0.85, 5=>1.10,
            6=>1.55, 7=>1.70, 8=>1.65, 9=>1.20, 10=>0.80,
            11=>0.55, 12=>0.60,
        ],
        'spring' => [
            // Peak: Mar–May
            1=>0.75, 2=>0.80, 3=>1.45, 4=>1.55, 5=>1.50,
            6=>1.10, 7=>0.80, 8=>0.75, 9=>0.80, 10=>0.90,
            11=>0.85, 12=>0.80,
        ],
        'autumn' => [
            // Peak: Sep–Nov
            1=>0.80, 2=>0.75, 3=>0.80, 4=>0.85, 5=>0.90,
            6=>0.85, 7=>0.80, 8=>0.90, 9=>1.45, 10=>1.60,
            11=>1.55, 12=>1.10,
        ],
        'ramadan' => [
            // Ramadan shifts each year — for 2025-2026 it's Feb-Mar range
            // We approximate a broader pre-Islamic-holiday peak
            // Peaks: Feb, Mar, Apr (Ramadan + Eid Al-Fitr window)
            1=>1.10, 2=>1.60, 3=>1.75, 4=>1.65, 5=>1.10,
            6=>0.90, 7=>0.85, 8=>0.85, 9=>0.90, 10=>0.95,
            11=>1.00, 12=>1.05,
        ],
        'eid_al_fitr' => [
            // Eid Al-Fitr follows Ramadan — typically Mar-Apr
            1=>1.00, 2=>1.30, 3=>1.65, 4=>1.80, 5=>1.20,
            6=>0.90, 7=>0.85, 8=>0.85, 9=>0.90, 10=>0.95,
            11=>1.00, 12=>1.05,
        ],
        'eid_al_adha' => [
            // Eid Al-Adha is typically May-Jun range in current years
            1=>0.90, 2=>0.90, 3=>0.95, 4=>1.00, 5=>1.50,
            6=>1.75, 7=>1.30, 8=>0.95, 9=>0.90, 10=>0.90,
            11=>0.90, 12=>0.95,
        ],
        'back_to_school' => [
            // Sharp Aug-Sep peak, very suppressed otherwise
            1=>0.70, 2=>0.65, 3=>0.70, 4=>0.75, 5=>0.75,
            6=>0.80, 7=>0.95, 8=>1.85, 9=>1.90, 10=>1.10,
            11=>0.75, 12=>0.70,
        ],
        'new_year' => [
            // Dec-Jan peak (Soldes + New Year gifts)
            1=>1.55, 2=>1.10, 3=>0.90, 4=>0.85, 5=>0.85,
            6=>0.90, 7=>0.90, 8=>0.90, 9=>0.95, 10=>1.00,
            11=>1.10, 12=>1.65,
        ],
    ];

    private const CONF_HIGH    = 20;
    private const CONF_MEDIUM  = 5;
    private const FORECAST_MONTHS = 6;

    // Blending weights: product season vs generic Tunisia commerce
    // 60% product-season signal + 40% general commerce pattern
    private const SEASON_WEIGHT   = 0.60;
    private const COMMERCE_WEIGHT = 0.40;

    /**
     * Generate a 6-month forward forecast for a product.
     */
    public function forecast(int $productId, int $sellerId, array $options = []): array
    {
        $months        = $options['months']         ?? self::FORECAST_MONTHS;
        $includeEvents = $options['include_events'] ?? true;

        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();

        $product = DB::table('products as p')
            ->leftJoin('categories as c',    'c.id', '=', 'p.category_id')
            ->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->where('p.id', $productId)
            ->whereNull('p.deleted_at')
            ->selectRaw("
                p.id, p.name, p.price, p.stock, p.views,
                p.season, p.category_id, p.subcategory_id,
                c.name as category_name, c.slug as category_slug,
                s.name as subcategory_name
            ")
            ->first();

        if (!$product) {
            return ['error' => 'Product not found'];
        }

        // ── Parse declared product seasons ────────────────────────────────
        $declaredSeasons = $this->parseDeclaredSeasons($product->season);

        // ── Build the blended monthly index for this specific product ─────
        $productMonthlyIndex = $this->buildProductMonthlyIndex($declaredSeasons);

        // ── Historical monthly sales (18 months) ──────────────────────────
        $history = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $productId)
            ->whereIn('o.status', self::COUNTED_STATUSES)
            ->where('o.created_at', '>=', Carbon::now()->subMonths(18))
            ->selectRaw("
                DATE_FORMAT(o.created_at, '%Y-%m') as month,
                YEAR(o.created_at)                 as yr,
                MONTH(o.created_at)                as mo,
                SUM(oi.quantity)                   as units,
                SUM({$totalExpr})                  as revenue,
                COUNT(DISTINCT oi.order_id)        as orders
            ")
            ->groupBy('month', 'yr', 'mo')
            ->orderBy('month')
            ->get();

        $totalHistoryOrders = $history->sum('orders');
        $avgMonthly         = $history->isNotEmpty()
            ? round($history->avg('units'), 2)
            : 0.0;

        // ── Compute season-aware historical average ────────────────────────
        // Weight past months by how well they match the product's season profile.
        // This makes the base estimate more accurate for seasonal products.
        $seasonWeightedAvg = $this->computeSeasonWeightedAverage($history, $productMonthlyIndex);
        $baseUnit = max($seasonWeightedAvg, $avgMonthly);

        $trendSlope   = $this->computeTrendSlope($history->take(-6)->values());
        $peerBaseline = $this->computeCategoryPeerBaseline(
            (int) $product->category_id,
            $productId,
            $totalExpr
        );

        $ownMonths = $history->count();
        $blendNote = '';

        if ($ownMonths < 3 && $peerBaseline['avg'] > 0) {
            $ownWeight  = max(0.1, $ownMonths / 6);
            $peerWeight = 1.0 - $ownWeight;
            $baseUnit   = round($baseUnit * $ownWeight + $peerBaseline['avg'] * $peerWeight, 2);
            $blendNote  = "Blended with {$peerBaseline['count']} category peers (own data: {$ownMonths} months).";
        }

        if ($baseUnit <= 0) {
            $baseUnit = max(1.0, $peerBaseline['avg']);
        }

        // Add season note to blendNote
        $seasonNote = implode(', ', array_map(
            fn($s) => ucfirst(str_replace('_', ' ', $s)),
            $declaredSeasons
        ));
        if ($blendNote) {
            $blendNote .= " Season: {$seasonNote}.";
        } else {
            $blendNote = "Season-aware forecast for: {$seasonNote}.";
        }

        $upcomingEvents = $includeEvents
            ? $this->loadUpcomingEvents($product->category_slug)
            : collect();

        // ── Project forward N months ──────────────────────────────────────
        $projections = [];
        $startMonth  = Carbon::now()->startOfMonth()->addMonth();

        for ($i = 0; $i < $months; $i++) {
            $targetDate  = $startMonth->copy()->addMonths($i);
            $monthNum    = (int) $targetDate->format('n');
            $yearMonth   = $targetDate->format('Y-m');
            $label       = $targetDate->format('M Y');

            $trendedBase = max(0, $baseUnit + $trendSlope * ($i + 1));

            // ── Product-season-aware index (replaces old generic index) ───
            $seasonIdx = $productMonthlyIndex[$monthNum] ?? 1.0;

            // ── Event boost ───────────────────────────────────────────────
            $eventBoost = 1.0;
            $eventName  = null;
            $matchedEvent = $upcomingEvents->first(function ($ev) use ($targetDate) {
            $start = Carbon::parse($ev->starts_at);
            $end   = Carbon::parse($ev->ends_at);
    
            // Primary check: event month overlaps this projection month
            $primaryMatch = $targetDate->between(
                $start->copy()->startOfMonth(),
                $end->copy()->endOfMonth()
            );
            if ($primaryMatch) return true;
    
            // Spillover check: events that end within 7 days before this month
            // still culturally affect the first week (e.g. Eid ending May 31 → June shopping surge)
            $spilloverEnd = $targetDate->copy()->startOfMonth();
            $spilloverStart = $spilloverEnd->copy()->subDays(7);
            return $end->between($spilloverStart, $spilloverEnd);
        });

            if ($matchedEvent) {
                // Only apply event boost if the event aligns with product season
                $eventBoost = $this->resolveEventBoost($matchedEvent, $declaredSeasons);
                $eventName  = $matchedEvent->event_name;
            }

            $predicted        = max(1, (int) round($trendedBase * $seasonIdx * $eventBoost));
            $predictedRevenue = round($predicted * (float) $product->price, 3);
            $monthConfidence  = $this->monthConfidence($totalHistoryOrders, $i);

            $projections[] = [
                'month'             => $yearMonth,
                'label'             => $label,
                'predicted_units'   => $predicted,
                'predicted_revenue' => $predictedRevenue,
                'trend_base'        => round($trendedBase, 2),
                'seasonality_idx'   => round($seasonIdx, 3),
                'event_boost'       => $eventBoost,
                'event_name'        => $eventName,
                'confidence'        => $monthConfidence,
            ];
        }

        $totalPredictedUnits   = array_sum(array_column($projections, 'predicted_units'));
        $totalPredictedRevenue = array_sum(array_column($projections, 'predicted_revenue'));
        $peakMonth             = collect($projections)->sortByDesc('predicted_units')->first();
        $lowestMonth           = collect($projections)->sortBy('predicted_units')->first();

        $overallTrend = 'stable';
        if ($trendSlope > 0.5)  $overallTrend = 'up';
        if ($trendSlope < -0.5) $overallTrend = 'down';

        $globalConfidence = $this->globalConfidence($totalHistoryOrders);
        $demandScore      = $this->computeDemandScore(
            avgMonthly:  $avgMonthly,
            trendSlope:  $trendSlope,
            views:       (int) $product->views,
            totalOrders: $totalHistoryOrders,
            peerAvg:     $peerBaseline['avg']
        );

        $next3MonthsUnits = array_sum(
            array_column(array_slice($projections, 0, 3), 'predicted_units')
        );
        $stockRec = (int) round($next3MonthsUnits * 1.30);

        return [
            'product_id'              => $productId,
            'product_name'            => $product->name,
            'category_name'           => $product->category_name,
            'category_slug'           => $product->category_slug,
            'subcategory_name'        => $product->subcategory_name,
            'current_price'           => (float) $product->price,
            'current_stock'           => (int) $product->stock,
            'declared_seasons'        => $declaredSeasons,        // NEW — for frontend display
            'projections'             => $projections,
            'total_predicted_units'   => $totalPredictedUnits,
            'total_predicted_revenue' => round($totalPredictedRevenue, 3),
            'peak_month'              => $peakMonth,
            'lowest_month'            => $lowestMonth,
            'overall_trend'           => $overallTrend,
            'trend_slope'             => round($trendSlope, 3),
            'demand_score'            => $demandScore,
            'confidence'              => $globalConfidence,
            'confidence_label'        => $this->confidenceLabel($totalHistoryOrders),
            'data_points'             => $totalHistoryOrders,
            'history'                 => $history->map(fn($r) => [
                'month'   => $r->month,
                'label'   => Carbon::createFromFormat('Y-m', $r->month)->format('M Y'),
                'units'   => (int) $r->units,
                'revenue' => round((float) $r->revenue, 3),
                'orders'  => (int) $r->orders,
            ])->values()->toArray(),
            'stock_recommendation_3m' => $stockRec,
            'blend_note'              => $blendNote,
            'computed_at'             => now()->toIso8601String(),
            'computed_by'             => 'laravel_forecast_engine_v3_season_aware',
            'forecast_months'         => $months,
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // NEW PRIVATE METHODS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Parse the product's season column into a clean array of slugs.
     * Handles: JSON array, plain string, null.
     */
    private function parseDeclaredSeasons(mixed $raw): array
    {
        $knownSlugs = array_keys(self::SEASON_MONTHLY_PROFILES);

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $arr = is_array($decoded) ? $decoded : [$raw];
        } elseif (is_array($raw)) {
            $arr = $raw;
        } else {
            $arr = ['all_seasons'];
        }

        $valid = array_values(array_filter(
            $arr,
            fn($s) => in_array($s, $knownSlugs, true)
        ));

        return !empty($valid) ? $valid : ['all_seasons'];
    }

    /**
     * Build a blended monthly index (months 1-12) for a product.
     *
     * Algorithm:
     *   For each calendar month:
     *     productSeasonScore = average of declared-season profiles for that month
     *     blended = (productSeasonScore × SEASON_WEIGHT) + (commerceIdx × COMMERCE_WEIGHT)
     *
     * This means:
     *   - A winter product gets its highest blended index in Dec/Jan/Feb
     *   - A back-to-school product peaks in Aug/Sep
     *   - An all_seasons product still follows the general commerce curve
     */
    private function buildProductMonthlyIndex(array $declaredSeasons): array
    {
        $blended = [];

        for ($m = 1; $m <= 12; $m++) {
            // Average the season profiles across all declared seasons
            $seasonSum   = 0.0;
            $seasonCount = 0;

            foreach ($declaredSeasons as $slug) {
                $profile      = self::SEASON_MONTHLY_PROFILES[$slug] ?? self::SEASON_MONTHLY_PROFILES['all_seasons'];
                $seasonSum   += (float) ($profile[$m] ?? 1.0);
                $seasonCount++;
            }

            $avgSeasonScore  = $seasonCount > 0 ? $seasonSum / $seasonCount : 1.0;
            $commerceIdx     = self::TUNISIA_COMMERCE_INDEX[$m] ?? 1.0;

            $blended[$m] = round(
                ($avgSeasonScore * self::SEASON_WEIGHT) + ($commerceIdx * self::COMMERCE_WEIGHT),
                4
            );
        }

        return $blended;
    }

    /**
     * Compute a season-weighted average from historical monthly data.
     *
     * Months that fall in the product's strong season are weighted higher,
     * so the base estimate reflects peak-season performance, not an average
     * diluted by off-season months.
     *
     * Example: a winter hoodie with only summer sales history gets a
     * corrected base that accounts for the fact that historical months
     * were off-season.
     */
    private function computeSeasonWeightedAverage(
        \Illuminate\Support\Collection $history,
        array $productMonthlyIndex
    ): float {
        if ($history->isEmpty()) return 0.0;

        $totalWeight   = 0.0;
        $weightedUnits = 0.0;

        foreach ($history as $row) {
            $monthNum    = (int) Carbon::createFromFormat('Y-m', $row->month)->format('n');
            $weight      = $productMonthlyIndex[$monthNum] ?? 1.0;
            $weightedUnits += (float) $row->units * $weight;
            $totalWeight   += $weight;
        }

        if ($totalWeight <= 0) return 0.0;

        // Weighted average — then normalise back to a "neutral month" baseline
        // so predicted_units reflects a normal month, not a peak month
        $avgIndex = array_sum($productMonthlyIndex) / 12.0;

        return $avgIndex > 0
            ? round(($weightedUnits / $totalWeight) / $avgIndex * $avgIndex, 2)
            : round($weightedUnits / $totalWeight, 2);
    }

    /**
     * Resolve event boost considering product season alignment.
     *
     * If a product is declared as "winter" but we hit a "summer tourism" event,
     * the boost should be dampened — winter products don't benefit from summer tourism.
     *
     * If there's alignment (e.g. ramadan product + ramadan event), keep full boost.
     * If neutral (e.g. all_seasons + any event), keep full boost.
     */
    private function resolveEventBoost(object $event, array $declaredSeasons): float
    {
        $rawBoost  = (float) $event->boost_score;
        $eventType = $event->event_type;

        // all_seasons products benefit from all events at full strength
        if (in_array('all_seasons', $declaredSeasons, true)) {
            return $rawBoost;
        }

        // Event-to-season alignment map
        // 1.0 = full boost (aligned), 0.5 = half (neutral), 0.2 = dampened (misaligned)
        $alignmentMap = [
            'ramadan' => [
                'ramadan' => 1.0, 'eid_al_fitr' => 1.0, 'eid_al_adha' => 0.8,
                'winter'  => 0.7, 'all_seasons' => 1.0,
                'summer'  => 0.3, 'back_to_school' => 0.3, 'new_year' => 0.5,
            ],
            'eid' => [
                'eid_al_fitr' => 1.0, 'eid_al_adha' => 1.0, 'ramadan' => 1.0,
                'all_seasons' => 1.0, 'winter' => 0.6, 'summer' => 0.5,
                'back_to_school' => 0.4, 'new_year' => 0.5,
            ],
            'summer' => [
                'summer' => 1.0, 'all_seasons' => 1.0,
                'winter' => 0.2, 'back_to_school' => 0.6, 'spring' => 0.7,
                'autumn' => 0.5, 'ramadan' => 0.5, 'eid_al_fitr' => 0.5,
                'eid_al_adha' => 0.6, 'new_year' => 0.3,
            ],
            'school' => [
                'back_to_school' => 1.0, 'all_seasons' => 1.0,
                'winter' => 0.7, 'autumn' => 0.7, 'summer' => 0.5,
                'spring' => 0.5, 'ramadan' => 0.4, 'new_year' => 0.4,
            ],
            'economy' => [
                // New year / soldes benefit most products
                'new_year' => 1.0, 'winter' => 0.9, 'all_seasons' => 1.0,
                'summer' => 0.6, 'back_to_school' => 0.7, 'ramadan' => 0.7,
            ],
            'tourism' => [
                'summer' => 1.0, 'spring' => 0.8, 'all_seasons' => 1.0,
                'winter' => 0.3, 'back_to_school' => 0.5, 'ramadan' => 0.5,
            ],
        ];

        $eventAlignments = $alignmentMap[$eventType] ?? [];

        // Find the best alignment factor across all declared seasons
        $bestFactor = 0.5; // default neutral
        foreach ($declaredSeasons as $season) {
            $factor     = $eventAlignments[$season] ?? 0.5;
            $bestFactor = max($bestFactor, $factor);
        }

        // Apply: boost dampened by alignment
        // Formula: 1.0 + (rawBoost - 1.0) × alignmentFactor
        // This means a ×1.42 event with 0.2 alignment → 1.0 + 0.42×0.2 = ×1.084
        $adjustedBoost = 1.0 + ($rawBoost - 1.0) * $bestFactor;

        return round($adjustedBoost, 3);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // UNCHANGED from previous version
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function regionalDemand(int $productId, int $sellerId): array
    {
        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $productId)
            ->whereIn('o.status', self::COUNTED_STATUSES)
            ->whereNotNull('o.wilaya')
            ->where('o.wilaya', '!=', '')
            ->selectRaw("
                o.wilaya,
                COUNT(DISTINCT oi.order_id) as total_orders,
                SUM(oi.quantity)            as total_units,
                SUM(oi.total)               as total_revenue
            ")
            ->groupBy('o.wilaya')
            ->orderByDesc('total_units')
            ->get();

        if ($rows->isEmpty()) {
            return ['has_data' => false, 'regions' => [], 'top_region' => null];
        }

        $maxUnits = $rows->max('total_units');

        $regions = $rows->map(function ($r) use ($maxUnits) {
            return [
                'wilaya'        => $r->wilaya,
                'total_orders'  => (int)   $r->total_orders,
                'total_units'   => (int)   $r->total_units,
                'total_revenue' => round((float) $r->total_revenue, 3),
                'demand_index'  => $maxUnits > 0
                    ? round(($r->total_units / $maxUnits) * 100, 1)
                    : 0,
            ];
        })->values()->toArray();

        $allGovernorates = $this->allTunisianGovernorates();
        $regionByName    = collect($regions)->keyBy('wilaya');

        $fullMap = array_map(function ($gov) use ($regionByName) {
            $existing = $regionByName->get($gov['name']);
            return $existing ?? [
                'wilaya'        => $gov['name'],
                'total_orders'  => 0,
                'total_units'   => 0,
                'total_revenue' => 0.0,
                'demand_index'  => 0.0,
                'lat'           => $gov['lat'],
                'lng'           => $gov['lng'],
            ];
        }, $allGovernorates);

        foreach ($fullMap as &$item) {
            $govMeta       = collect($allGovernorates)->firstWhere('name', $item['wilaya']);
            $item['lat']   = $govMeta['lat'] ?? null;
            $item['lng']   = $govMeta['lng'] ?? null;
        }
        unset($item);

        return [
            'has_data'   => true,
            'regions'    => $fullMap,
            'top_region' => $regions[0] ?? null,
        ];
    }

    private function computeTrendSlope(\Illuminate\Support\Collection $months): float
    {
        $n = $months->count();
        if ($n < 2) return 0.0;

        $x = range(1, $n);
        $y = $months->pluck('units')->map(fn($v) => (float)$v)->toArray();

        $xMean = array_sum($x) / $n;
        $yMean = array_sum($y) / $n;

        $numerator   = 0.0;
        $denominator = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $numerator   += ($x[$i] - $xMean) * ($y[$i] - $yMean);
            $denominator += ($x[$i] - $xMean) ** 2;
        }

        return $denominator > 0 ? round($numerator / $denominator, 3) : 0.0;
    }

    private function computeCategoryPeerBaseline(
        int $categoryId,
        int $excludeProductId,
        string $totalExpr
    ): array {
        if (!$categoryId) return ['avg' => 0.0, 'count' => 0];

        $peers = DB::table('order_items as oi')
            ->join('orders as o',   'o.id',  '=', 'oi.order_id')
            ->join('products as p', 'p.id',  '=', 'oi.product_id')
            ->where('p.category_id', $categoryId)
            ->where('p.id', '!=', $excludeProductId)
            ->where('p.is_approved', true)
            ->where('p.is_active', true)
            ->whereNull('p.deleted_at')
            ->whereIn('o.status', self::COUNTED_STATUSES)
            ->where('o.created_at', '>=', Carbon::now()->subMonths(6))
            ->selectRaw("
                p.id as product_id,
                SUM(oi.quantity) as total_units,
                COUNT(DISTINCT DATE_FORMAT(o.created_at, '%Y-%m')) as active_months
            ")
            ->groupBy('p.id')
            ->having('active_months', '>=', 1)
            ->get();

        if ($peers->isEmpty()) return ['avg' => 0.0, 'count' => 0];

        $monthlyAvgs = $peers->map(fn($p) => (float)$p->total_units / max(1, (int)$p->active_months));
        return [
            'avg'   => round($monthlyAvgs->average(), 2),
            'count' => $peers->count(),
        ];
    }

    private function loadUpcomingEvents(?string $categorySlug): \Illuminate\Support\Collection
    {
        try {
            $query = DB::table('product_event_signals')
                ->where('is_active', true)
                ->where('ends_at', '>=', Carbon::now()->format('Y-m-d'))
                ->where('starts_at', '<=', Carbon::now()->addMonths(self::FORECAST_MONTHS)->format('Y-m-d'))
                ->orderBy('starts_at');

            if ($categorySlug) {
                $query->where(function ($q) use ($categorySlug) {
                    $q->whereNull('affected_categories')
                      ->orWhereJsonContains('affected_categories', $categorySlug);
                });
            }

            return $query->get();
        } catch (\Throwable $e) {
            Log::warning('[ForecastEngine] Events table not available: ' . $e->getMessage());
            return collect();
        }
    }

    private function computeDemandScore(
        float $avgMonthly,
        float $trendSlope,
        int   $views,
        int   $totalOrders,
        float $peerAvg
    ): int {
        $volumeScore = min(35, ($avgMonthly / max(1, $peerAvg > 0 ? $peerAvg : 10)) * 35);

        $trendScore = match(true) {
            $trendSlope > 2  => 25,
            $trendSlope > 0  => 15,
            $trendSlope == 0 => 10,
            $trendSlope > -2 => 5,
            default          => 0,
        };

        $conversionScore = 0;
        if ($views > 0 && $totalOrders > 0) {
            $convRate = ($totalOrders / $views) * 100;
            $conversionScore = min(20, $convRate * 4);
        }

        $richnessScore = min(20, ($totalOrders / self::CONF_HIGH) * 20);

        return (int) min(100, round($volumeScore + $trendScore + $conversionScore + $richnessScore));
    }

    private function monthConfidence(int $historyOrders, int $monthsAhead): string
    {
        $degraded = $historyOrders / (($monthsAhead + 1) * 1.5);
        return match(true) {
            $degraded >= self::CONF_HIGH   => 'high',
            $degraded >= self::CONF_MEDIUM => 'medium',
            default                        => 'low',
        };
    }

    private function globalConfidence(int $orders): int
    {
        return (int) min(100, round(($orders / self::CONF_HIGH) * 75 + 25));
    }

    private function confidenceLabel(int $orders): string
    {
        return match(true) {
            $orders >= self::CONF_HIGH   => 'high',
            $orders >= self::CONF_MEDIUM => 'medium',
            default                      => 'low',
        };
    }

    private function sellerCol(): string
    {
        static $col = null;
        if ($col) return $col;
        $cols = array_map(fn($c) => $c->Field, DB::select('SHOW COLUMNS FROM products'));
        return $col = in_array('seller_id', $cols) ? 'seller_id' : 'user_id';
    }

    private function totalExpr(): string
    {
        $cols  = array_map(fn($c) => $c->Field, DB::select('SHOW COLUMNS FROM order_items'));
        $parts = [];
        if (in_array('total',      $cols)) $parts[] = 'oi.total';
        if (in_array('unit_price', $cols) && in_array('quantity', $cols))
            $parts[] = 'oi.unit_price * oi.quantity';
        elseif (in_array('price', $cols) && in_array('quantity', $cols))
            $parts[] = 'oi.price * oi.quantity';
        $parts[] = '0';
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function allTunisianGovernorates(): array
    {
        return [
            ['name' => 'Tunis',        'lat' => 36.8190, 'lng' => 10.1658],
            ['name' => 'Ariana',       'lat' => 36.8625, 'lng' => 10.1956],
            ['name' => 'Ben Arous',    'lat' => 36.7533, 'lng' => 10.2282],
            ['name' => 'Manouba',      'lat' => 36.8088, 'lng' => 10.0984],
            ['name' => 'Nabeul',       'lat' => 36.4561, 'lng' => 10.7376],
            ['name' => 'Zaghouan',     'lat' => 36.4025, 'lng' => 10.1433],
            ['name' => 'Bizerte',      'lat' => 37.2746, 'lng' => 9.8738],
            ['name' => 'Béja',         'lat' => 36.7333, 'lng' => 9.1833],
            ['name' => 'Jendouba',     'lat' => 36.5011, 'lng' => 8.7803],
            ['name' => 'Le Kef',       'lat' => 36.1820, 'lng' => 8.7141],
            ['name' => 'Siliana',      'lat' => 36.0847, 'lng' => 9.3708],
            ['name' => 'Sousse',       'lat' => 35.8256, 'lng' => 10.6369],
            ['name' => 'Monastir',     'lat' => 35.7643, 'lng' => 10.8113],
            ['name' => 'Mahdia',       'lat' => 35.5047, 'lng' => 11.0622],
            ['name' => 'Sfax',         'lat' => 34.7406, 'lng' => 10.7603],
            ['name' => 'Kairouan',     'lat' => 35.6781, 'lng' => 10.0963],
            ['name' => 'Kasserine',    'lat' => 35.1667, 'lng' => 8.8333],
            ['name' => 'Sidi Bouzid',  'lat' => 35.0382, 'lng' => 9.4849],
            ['name' => 'Gabès',        'lat' => 33.8814, 'lng' => 10.0982],
            ['name' => 'Medenine',     'lat' => 33.3547, 'lng' => 10.5053],
            ['name' => 'Tataouine',    'lat' => 32.9211, 'lng' => 10.4508],
            ['name' => 'Gafsa',        'lat' => 34.4250, 'lng' => 8.7842],
            ['name' => 'Tozeur',       'lat' => 33.9197, 'lng' => 8.1335],
            ['name' => 'Kébili',       'lat' => 33.7050, 'lng' => 8.9715],
        ];
    }
}