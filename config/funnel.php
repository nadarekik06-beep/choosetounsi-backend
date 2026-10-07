<?php

/*
|--------------------------------------------------------------------------
| Visitor Insights (Black Pepper → Analyse des visiteurs)
|--------------------------------------------------------------------------
| Behavioral funnel per product: impressions → clicks → views → cart →
| checkout → orders. See docs/VISITOR_INSIGHTS.md.
*/

return [

    'timezone' => env('FUNNEL_TIMEZONE', 'Africa/Tunis'),

    // Periods the page offers (days).
    'periods' => [7, 30, 90],

    // Traffic sources, in display order. A storefront section (ProductCard `section`) maps
    // to a source by prefix; anything unknown but internal counts as "home".
    'sources' => ['search', 'category', 'home', 'sponsored', 'storefront', 'external', 'direct'],
    'section_prefixes' => [
        'sponsored'  => ['sponsored', 'ad_', 'ads_'],
        'search'     => ['search'],
        'category'   => ['category', 'subcategory', 'brand', 'deals'],
        'storefront' => ['seller_store', 'store', 'product_from_seller'],
        'external'   => ['external'],
        'direct'     => ['direct'],
    ],

    // How long after a click the product page view is attributed to that click's source.
    'click_attribution_minutes' => 30,
    // An order is attributed to the buyer's last view of the product within this many days.
    'order_attribution_days' => 7,

    // Raw impressions / checkout starts are only needed until they are rolled up.
    'raw_retention_days' => 120,

    // Server-side dedupe (seconds): one impression per session, product and listing context.
    'impression_dedupe_seconds' => 6 * 3600,

    // Historical exclusion (backfill): a session with more product views than this in one
    // day is treated as a bot.
    'bot_views_per_day' => 150,

    // ── Diagnosis ─────────────────────────────────────────────────────────
    'min_views'           => 30,    // below this a product gets "not enough data"
    'min_impressions'     => 150,   // CTR is only judged above this
    'min_carts'           => 5,     // cart → order is only judged above this
    'min_checkouts'       => 5,
    'min_listed_days'     => 7,     // visibility is judged once a product has been live this long
    'low_factor'          => 0.6,   // a rate below 60 % of the benchmark is a problem
    'high_severity_factor'=> 0.3,   // below 30 % → high severity
    'visibility_factor'   => 0.35,  // views below 35 % of the benchmark's views per product
    'price_high_factor'   => 1.10,  // price above the median × this
    'quality_low_score'   => 60,    // ProductQualityService score
    'shipping_high_factor'=> 1.5,   // delivery fee above the median × this

    // Benchmarks: a product needs this sample to count in a median.
    'bench_min_views'       => 20,
    'bench_min_impressions' => 100,
    'bench_min_carts'       => 3,

    // Before / after an applied action.
    'impact' => [
        'window_days'   => 14,
        'min_after_days'=> 7,
        'min_views'     => 30,  // per side
    ],
];
