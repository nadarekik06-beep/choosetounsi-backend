<?php

/*
| Product search. The search bar is plain MySQL (product_search_index, see
| App\Services\Search\ProductSearch): keyword-first, synonyms.txt, spelling correction.
| Meilisearch + the embedding service (services.ai, choosetounsi-ai-service) serve "search by
| image" and, when no word matched at all, an optional semantic fallback.
*/

return [

    // Meilisearch listens on localhost only; the master key never leaves the backend.
    'meilisearch' => [
        'host'    => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key'     => env('MEILISEARCH_KEY'),
        'timeout' => (float) env('MEILISEARCH_TIMEOUT', 2),
    ],

    // Observers/jobs that keep the indexes in sync (off in the test suite).
    'indexing' => (bool) env('SEARCH_INDEXING', true),

    'indexes' => [
        'products' => env('SEARCH_PRODUCTS_INDEX', 'products'),
        'images'   => env('SEARCH_IMAGES_INDEX', 'product_images'),
    ],

    'synonyms_path' => resource_path('search/synonyms.txt'),

    // ── Search bar ────────────────────────────────────────────────────────
    'semantic' => [
        // Fallback only, when no keyword matched: multilingual text vectors. Off = keyword search only.
        'enabled'  => (bool) env('SEARCH_SEMANTIC', true),
        // A result found ONLY by vectors (no keyword matched) must reach this ranking score
        // ((1 + cosine) / 2 in Meilisearch). Calibrated on the live catalog: 99% of
        // query/unrelated-product pairs score <= 0.73, while the queries where vectors help
        // (Arabic, French phrases) reach their products at 0.74-0.88.
        'min_score' => (float) env('SEARCH_SEMANTIC_MIN_SCORE', 0.73),
        'timeout'  => (float) env('SEARCH_EMBED_TIMEOUT', 1.5),
    ],

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
