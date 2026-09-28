<?php

/*
|--------------------------------------------------------------------------
| Homepage personalization / recommendations
|--------------------------------------------------------------------------
| Tuning knobs for InteractionTracker, InterestProfileService and
| HomeFeedBuilder. Everything here is safe to change without a migration.
*/

return [

    // Signal strength per interaction type (used by the interest profile).
    'weights' => [
        'purchase'        => 5,
        'cart_add'        => 4,
        'favorite_add'    => 4,
        'follow'          => 4,
        'click'           => 2,
        'view'            => 1,
        'search'          => 1,
        'favorite_remove' => -2,
        'unfollow'        => -3,
    ],

    // Score halves every N days, so recent activity dominates.
    'half_life_days' => 14,

    // Only look this far back when building a profile.
    'profile_window_days' => 120,

    // Recompute a profile at most this often, even if nothing changed (minutes).
    'profile_ttl_minutes' => 30,

    // The same actor + product + event is only counted once inside this window (seconds).
    'dedupe_seconds' => [
        'view'   => 1800,
        'click'  => 300,
        'search' => 60,
    ],

    // Events the storefront may send via POST /api/track. Everything else
    // (cart, favourites, follows, purchases, searches) is recorded server-side.
    'client_events' => ['view', 'click'],

    // Raw interactions older than this are pruned by recommendations:prune.
    'retention_days' => 365,

    // Already-bought products stay excluded from recommendations, EXCEPT products in
    // these consumable categories/subcategories, which come back after N days.
    'repurchase' => [
        'after_days'          => 30,
        'category_slugs'      => ['food-grocery', 'beauty-personal-care'],
        // Health & Wellness: only its consumable subcategories (not devices / essential oils)
        'subcategory_slugs'   => ['vitamins', 'herbal-tea', 'protein-powder'],
    ],

    'feed' => [
        'row_size'            => 16,     // target products per row
        'min_section_size'    => 4,      // optional/personal rows below this are hidden
        'best_seller_days'    => 90,

        // Small catalog (launch, dev): strict rules would leave the page empty, so the
        // per-seller cap is lifted and a product may appear in up to 2 rows (unique first).
        'small_catalog' => [
            'max_products'      => 60,   // fewer eligible products than this → small
            'min_sellers'       => 5,    // or fewer distinct sellers than this → small
            'max_appearances'   => 2,
            'min_section_size'  => 2,
        ],
        'max_per_seller'      => 3,
        'max_per_category'    => 4,
        'exploration_ratio'   => 0.15,
        'seller_plan_weight'  => 0.05,   // tie-breaker only, never paid visibility
        'trending_days'       => 7,
        'new_arrival_days'    => 30,
        'pool_cache_minutes'  => 10,
    ],

    'ai' => [
        'similar_timeout'     => 2,      // seconds
        'down_flag_minutes'   => 2,
    ],
];
