<?php

/*
|--------------------------------------------------------------------------
| Sales forecast (seller dashboard → Outils IA → Ventes)
|--------------------------------------------------------------------------
| Every number shown to sellers comes from App\Services\Forecast (statistics on
| our own sales). Groq only writes the explanation text. See docs/FORECAST.md.
*/

return [

    // seller_orders statuses that count as a real sale. pending = not confirmed yet.
    'sale_statuses' => ['confirmed', 'handed_to_courier', 'out_for_delivery', 'delivered', 'completed'],

    // How far back the daily series goes.
    'history_days' => 730,

    // ── Data tiers ─────────────────────────────────────────────────────────
    // Own model only with at least this many orders over at least this many days
    // of listing; below that the seller's data is blended with the category prior.
    'own_min_orders' => 10,
    'own_min_days'   => 56,

    // Category prior: anonymized sales of similar products (same category, price
    // within ×0.5–×2) from other shops. Too thin → no estimate at all.
    'prior' => [
        'window_days'  => 90,
        'min_products' => 5,
        'min_sellers'  => 3,
        'min_orders'   => 10,
        'price_band'   => [0.5, 2.0],
        // Cap on the prior's weight, in "pseudo-days" of the seller's own listing.
        'max_strength_days' => 60,
    ],

    // ── Calendar effects ───────────────────────────────────────────────────
    // A measured event effect is used in the forecast only when both the event
    // window and the surrounding weeks had at least this many orders in that
    // category (±36 % sampling error at 30 orders — see docs/FORECAST.md).
    'event_effect_min_orders' => 30,
    'event_baseline_weeks'    => 4,     // weeks before + after the event used as baseline
    'event_reminder_days'     => [0, 42], // remind when an event starts within 6 weeks

    // Measured promotion uplift applied to scheduled promotions only with this many promo orders.
    'promo_uplift_min_orders' => 5,

    // ── Stock defaults (seller-editable) ───────────────────────────────────
    'default_lead_time_days' => 7,
    'default_safety_days'    => 7,
    'reorder_cover_days'     => 28,   // a reorder should cover 4 weeks after it arrives

    // ── Action thresholds ──────────────────────────────────────────────────
    'dormant_days'          => 60,
    'dormant_min_stock'     => 5,
    'low_cart_min_views'    => 50,    // over 30 days
    'low_cart_rate'         => 0.03,  // used when the category has no measurable rate
    'low_views_max'         => 20,    // fewer views than this over 30 days → visibility tip

    // ── Serving ────────────────────────────────────────────────────────────
    'refresh_cooldown_minutes' => 10,
    'queue'                    => env('FORECAST_QUEUE', 'default'),
    'timezone'                 => env('FORECAST_TIMEZONE', 'Africa/Tunis'),
];
