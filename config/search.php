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

    // ── Search by image ─────────────────────────────────────────────────
    'image' => [
        // CLIP cosine similarity between two photos (search converts Meilisearch's
        // (1 + cosine) / 2 back to cosine). Calibrated on the live catalog: another photo of
        // the same product >= 0.79, unrelated real photos <= 0.71 (99% of cross-category
        // pairs <= 0.66).
        'min_similarity'  => (float) env('IMAGE_SEARCH_MIN_SIMILARITY', 0.72),
        // Also drop anything this far below the best match.
        'max_gap'         => (float) env('IMAGE_SEARCH_MAX_GAP', 0.18),
        // Soft boost for the category most of the nearest images belong to (no hard filter).
        'category_boost'  => (float) env('IMAGE_SEARCH_CATEGORY_BOOST', 0.02),
        'timeout'         => (float) env('IMAGE_EMBED_TIMEOUT', 10),
    ],
];
