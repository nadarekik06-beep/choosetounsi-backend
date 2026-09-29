<?php

/*
|--------------------------------------------------------------------------
| Sponsoring / ads
|--------------------------------------------------------------------------
| `defaults` are the tunable numbers of the ad engine. Admins override any of
| them in platform_settings under "ads.<key>" (see App\Services\Ads\AdSettings),
| so nothing here should be read with config() directly — always go through
| AdSettings. Money is in TND.
|
| The keys outside `defaults` are deployment settings, not admin-tunable.
*/

return [

    'defaults' => [

        // ── Budget & bidding ─────────────────────────────────────────────
        'min_daily_budget'         => 3.000,
        'min_top_up'               => 10.000,
        'min_cpc'                  => 0.200,
        'category_min_cpc'         => [],      // { "<category_id>": 0.350, ... }
        'suggested_cpc_window_days' => 14,     // median winning CPC over this window
        'suggested_cpc_multiplier' => 1.5,     // fallback: min_cpc × this
        'gsp_increment'            => 0.010,   // second-price step above the runner-up

        // ── Plan tiers (free = Green, red = Red, black = Black Pepper) ──
        'tier_click_discount' => ['free' => 0.00, 'red' => 0.15, 'black' => 0.30],
        'monthly_credit'      => ['free' => 0.000, 'red' => 10.000, 'black' => 40.000],

        // ── Events, fraud, attribution ───────────────────────────────────
        'click_dedupe_hours'          => 24,
        'impression_dedupe_minutes'   => 30,
        'attribution_days'            => 7,
        'frequency_cap_per_day'       => 3,    // impressions per actor per campaign per day
        'bot_max_clicks_per_minute'   => 10,   // per IP hash; above → non-billable

        // ── Ranking ──────────────────────────────────────────────────────
        'readiness_threshold'  => 60,          // 0..100; below → cannot be boosted
        'pctr_prior_strength'  => 50,          // k in (clicks + k·prior) / (impressions + k)
        'pctr_prior'           => [
            'home_row' => 0.020, 'home_inline' => 0.020, 'search_top' => 0.035, 'category_top' => 0.025,
            'product_similar' => 0.020, 'cart_cross_sell' => 0.015, 'entry_popup' => 0.030, 'email_digest' => 0.040,
        ],
        'quality_min'          => 0.5,
        'quality_max'          => 1.2,
        // Weight of personal interest vs. page context in relevance, per placement (context = 1 − personal).
        'relevance_personal_weight' => [
            'home_row' => 1.0, 'home_inline' => 1.0, 'search_top' => 0.2, 'category_top' => 0.4,
            'product_similar' => 0.3, 'cart_cross_sell' => 0.3, 'entry_popup' => 1.0, 'email_digest' => 1.0,
        ],
        'min_relevance' => [
            'home_row' => 0.25, 'home_inline' => 0.30, 'search_top' => 0.35, 'category_top' => 0.30,
            'product_similar' => 0.35, 'cart_cross_sell' => 0.30, 'email_digest' => 0.40,
        ],                                      // entry_popup: popup_min_relevance

        // ── Placements (buyer protection) ────────────────────────────────
        'max_ads' => [
            'home_row' => 8, 'home_inline' => 2, 'search_top' => 2, 'category_top' => 2,
            'product_similar' => 4, 'cart_cross_sell' => 2, 'entry_popup' => 1, 'email_digest' => 2,
        ],
        'reserved_slots' => [1, 7],            // 1-based grid positions for search_top / category_top

        // ── Entry popup ──────────────────────────────────────────────────
        'popup_enabled'               => true,
        'popup_min_relevance'         => 0.6,
        'popup_delay_seconds'         => 8,
        'popup_dismiss_hours'         => 24,
        'popup_dismiss_days_after_3'  => 7,

        // ── Marketing e-mails ────────────────────────────────────────────
        'digest_enabled'           => true,
        'digest_day'               => 'friday',
        'digest_time'              => '18:00',
        'digest_products'          => 6,
        'interest_emails_enabled'  => true,
        'marketing_email_gap_days' => 7,       // max one marketing e-mail per user per N days

        // ── Seller alerts & optimisation ─────────────────────────────────
        'budget_alert_ratio'        => 0.8,    // "80 % of today's budget spent"
        'wallet_low_days'           => 3,      // wallet < N days of budget
        'low_performance_after_days' => 3,
        'optimizer_min_clicks'      => 30,

        // ── Forecast (used until the category has campaign history) ──────
        'forecast_reach_share'      => 0.30,   // share of category viewers an ad can reach per day
        'forecast_default_cvr'      => 0.020,  // orders per click when the category has no data
    ],

    // Deployment settings (not admin-tunable). Every ad date (daily budgets, roll-ups,
    // credit expiry, stats) is a day in this timezone — see App\Services\Ads\AdClock.
    'timezone'            => env('ADS_TIMEZONE', 'Africa/Tunis'),
    'token_ttl_hours'     => 24,
    'email_token_ttl_days' => 7,
    'eligible_cache_seconds' => 60,
    'settings_cache_seconds' => 300,

    // Wallet top-up gateways. sandbox pays instantly without money (local development
    // only); manual = D17/bank transfer confirmed by an admin; konnect/flouci are
    // hosted-page stubs until their integration is written.
    'gateways' => [
        'sandbox' => (bool) env('ADS_SANDBOX_TOP_UP', env('APP_ENV') === 'local'),
        'manual'  => (bool) env('ADS_MANUAL_TOP_UP', true),
        'konnect' => false,
        'flouci'  => false,
    ],
];
