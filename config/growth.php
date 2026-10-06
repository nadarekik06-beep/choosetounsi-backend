<?php

/*
|--------------------------------------------------------------------------
| Growth Radar (seller dashboard → Growth Radar)
|--------------------------------------------------------------------------
| Every number on a card is computed by App\Services\GrowthRadar from our own
| data (rules and simple statistics). Groq only rephrases the headline.
| See docs/GROWTH_RADAR.md.
*/

return [

    'timezone' => env('GROWTH_TIMEZONE', 'Africa/Tunis'),

    // Plan feature that unlocks the full feed. Other plans get the score + one locked card.
    'full_feature' => 'black_hub',

    // ── Privacy floor (cross-seller stats only) ────────────────────────────
    // Category prices, platform conversion and search volumes are shown only when
    // they aggregate at least this many shops, or at least this many events.
    // A seller's own data has no floor, only a confidence label.
    'privacy' => [
        'min_sellers' => 5,
        'min_events'  => 30,
        'min_products' => 8,    // products behind a category price range
    ],

    // ── Feed ───────────────────────────────────────────────────────────────
    'max_cards'        => 7,
    'dismiss_memory_weeks' => 4,    // a dismissed card does not come back for this long
    'snooze_days'      => [3, 7],

    // ── Detectors ──────────────────────────────────────────────────────────
    'window_days' => 30,

    'leaking' => [
        'min_views'      => 40,     // over the window
        'gap_factor'     => 0.5,    // conversion below half the benchmark
        'fallback_rate'  => 0.02,   // orders per view when no benchmark exists (labelled low)
        'short_description' => 200, // characters
        'min_images'     => 3,
    ],

    'price' => [
        'high_factor'   => 1.10,    // above p75 × this → too high
        'low_factor'    => 0.90,    // below p25 × this → room to raise
        'min_discount'  => 5,
        'max_discount'  => 30,
        'test_days'     => 7,
    ],

    'hidden_demand' => [
        'max_results'  => 2,        // a query with this many results or fewer is "missed"
        'max_cards'    => 2,
        'search_to_order' => [0.01, 0.04],   // share of searches that could become an order
    ],

    'warm' => [
        'min_audience'   => 3,      // never target (or let a seller infer) a single person
        'cart_days'      => 30,
        'favorite_days'  => 60,
        'coupon_pct'     => 10,
        'coupon_days'    => 7,
        'buy_rate'       => [0.08, 0.20],   // share of warm buyers expected to order
        'buyer_cap_days' => 7,      // at most one targeted coupon per buyer per week, all sellers
    ],

    'seasonal' => [
        'horizon_days' => [5, 49],  // events starting in this window get a card
        'default_lift' => [0.10, 0.25],
        'max_cards'    => 1,
        // Days before the event a promotion should start, by event key (default 7).
        'lead_days'    => ['ramadan' => 10, 'aid_fitr' => 10, 'aid_adha' => 10, 'back_to_school' => 14,
                           'summer' => 0, 'winter_sales' => 0, 'summer_sales' => 0, 'wedding_season' => 7,
                           'black_friday' => 0, 'new_year' => 7],
        'promo_days'   => 10,
        'promo_pct'    => 10,
    ],

    'dead_stock' => [
        'days'       => 45,         // no unit sold for this long
        'min_stock'  => 5,
        'low_views'  => 20,         // fewer views → visibility problem (boost), not price
        'clearance_pct' => 20,
        'clearance_days' => 10,
        'sell_through' => [0.10, 0.30],
    ],

    'timing' => [
        'weeks'      => 8,
        'min_events' => 300,        // platform events behind the traffic pattern
        'min_lift'   => 1.25,       // best slot vs average, below that no card
        'flash_hours' => 48,
        'flash_pct'  => 15,
    ],

    // Orders-per-view benchmarks are capped here: above it the data is incomplete
    // (orders without tracked views), not a real conversion rate.
    'max_benchmark_rate' => 0.08,

    // ── Confidence (own data volume) ───────────────────────────────────────
    'confidence_views'  => ['medium' => 100, 'high' => 300],

    // ── Results ────────────────────────────────────────────────────────────
    'results' => [
        'after_days'     => 7,      // measured this long after the action ends
        'edit_days'      => 14,     // window for edits / new listings / bundles
        'max_window'     => 14,
        'min_units'      => 5,      // fewer units in baseline + during → "unclear"
        'z'              => 1.64,   // one-sided 95 % on the unit counts
        'show_days'      => 14,     // a result card stays in the feed this long
    ],

    // ── Learning per seller ────────────────────────────────────────────────
    'learning' => [
        'min_actions' => 2,         // measured actions of a kind before it changes anything
        'multiplier'  => [0.6, 1.5],
    ],

    // ── Notifications ──────────────────────────────────────────────────────
    'notify' => [
        'min_impact_high' => 50,    // TND
        'email_gap_days'  => 7,
    ],

    'ai_headlines' => env('GROWTH_AI_HEADLINES', true),
    'refresh_cooldown_minutes' => 10,
    'queue' => env('GROWTH_QUEUE', 'default'),
];
