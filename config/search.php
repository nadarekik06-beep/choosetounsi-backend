<?php

/*
| Product search, all on MySQL:
|  - search bar: product_search_index (App\Services\Search\ProductSearch): keyword-first,
|    synonyms.txt, spelling correction;
|  - search by photo: image_fingerprints (App\Services\Search\ImageSearch) + the AI service
|    (services.ai.url, choosetounsi-ai-service) for the photo vectors.
*/

return [

    // Observers/jobs that keep the indexes in sync (off in the test suite).
    'indexing' => (bool) env('SEARCH_INDEXING', true),

    'synonyms_path' => resource_path('search/synonyms.txt'),

    // ── Search bar ────────────────────────────────────────────────────────
    // Business signals added to relevance (0..1). Small on purpose: they reorder close matches,
    // they don't push an irrelevant product above a relevant one.
    'boosts' => [
        'in_stock' => 0.06,
        'rating'   => 0.03,   // × average rating / 5, damped below 5 reviews
        'sales'    => 0.03,   // × log-scaled units sold over 90 days, relative to the best seller
        'featured' => 0.01,
    ],

    // Fewer best results than this: spelling correction is tried, and the query is logged as
    // a "low result" query (admin: missed searches).
    'low_results' => (int) env('SEARCH_LOW_RESULTS', 3),

    // ── Search by image (MySQL image_fingerprints + choosetounsi-ai-service) ────
    // Similarities are CLIP cosines between the customer's photo (averaged over 3 views) and a
    // catalog photo. Tuned on the live catalog with phone-style photos (see docs/SEARCH.md).
    'image' => [
        // Rank score (similarity + boosts) a product needs to be shown at all.
        'min_score'        => (float) env('IMAGE_SEARCH_MIN_SCORE', 0.74),
        // Raw similarity of a "Correspondances exactes" result (the same item, another photo).
        'exact_similarity' => (float) env('IMAGE_SEARCH_EXACT', 0.86),
        // Also drop "similar" results this far below the best one.
        'max_gap'          => (float) env('IMAGE_SEARCH_MAX_GAP', 0.15),

        // Category detection: query vs each category's centroid (mean photo vector).
        // Confident = best centroid at least centroid_min and either ahead of the second by
        // margin_min or confirmed by the nearest photos' majority. Not confident: no category boost.
        'centroid_min'     => (float) env('IMAGE_SEARCH_CENTROID_MIN', 0.60),
        'margin_min'       => (float) env('IMAGE_SEARCH_MARGIN_MIN', 0.02),
        // The second category is kept when it is this close to the first.
        'second_gap'       => (float) env('IMAGE_SEARCH_SECOND_GAP', 0.015),
        // Boosts added to the similarity.
        'category_boost'   => (float) env('IMAGE_SEARCH_CATEGORY_BOOST', 0.05),   // predicted category (confident only)
        'vote_boost'       => (float) env('IMAGE_SEARCH_VOTE_BOOST', 0.02),       // category of most of the 5 nearest products
        'color_boost'      => (float) env('IMAGE_SEARCH_COLOR_BOOST', 0.03),      // same dominant color, fading to 0 at color_distance
        'color_distance'   => (float) env('IMAGE_SEARCH_COLOR_DISTANCE', 45),     // CIELAB ΔE
        // Confident prediction: products of other categories need at least this similarity.
        'outside_min'      => (float) env('IMAGE_SEARCH_OUTSIDE_MIN', 0.82),

        'exact_limit'      => 12,
        'similar_limit'    => 24,
        'fallback_limit'   => 12,

        'timeout'          => (float) env('IMAGE_SEARCH_TIMEOUT', 3),    // seconds, AI service call
        'per_minute'       => (int) env('IMAGE_SEARCH_PER_MINUTE', 10),  // per user / IP
        'cache_minutes'    => (int) env('IMAGE_SEARCH_CACHE_MINUTES', 10),
        'log_days'         => (int) env('IMAGE_SEARCH_LOG_DAYS', 180),
    ],
];
