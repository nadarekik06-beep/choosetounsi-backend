<?php

/*
| Product search: Meilisearch (keyword + typo tolerance + synonyms, optional semantic
| vectors) for the search bar, CLIP vectors for "search by image". Vectors come from the
| embedding service configured in services.ai (choosetounsi-ai-service).
*/

return [

    // Meilisearch listens on localhost only; the master key never leaves the backend.
    'meilisearch' => [
        'host'    => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key'     => env('MEILISEARCH_KEY'),
        'timeout' => (float) env('MEILISEARCH_TIMEOUT', 2),
    ],

    'indexes' => [
        'products' => env('SEARCH_PRODUCTS_INDEX', 'products'),
        'images'   => env('SEARCH_IMAGES_INDEX', 'product_images'),
    ],

    'synonyms_path' => resource_path('search/synonyms.txt'),

    // ── Search bar ────────────────────────────────────────────────────────
    'semantic' => [
        // Multilingual text vectors (FR/AR/EN meet in one space). Off = keyword search only.
        'enabled'  => (bool) env('SEARCH_SEMANTIC', true),
        // Share of the hybrid ranking given to vector similarity (0 = keyword only, 1 = vectors only).
        'ratio'    => (float) env('SEARCH_SEMANTIC_RATIO', 0.35),
        // A result found ONLY by vectors (no keyword matched) must reach this ranking score.
        'min_score' => (float) env('SEARCH_SEMANTIC_MIN_SCORE', 0.62),
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

    // Fewer results than this is logged as a "low result" query (admin: missed searches).
    'low_results' => (int) env('SEARCH_LOW_RESULTS', 3),

    // ── Search by image ─────────────────────────────────────────────────
    'image' => [
        // CLIP cosine similarity between two photos. Unrelated product photos already score
        // ~0.55-0.70, the same product from another angle ~0.85+.
        'min_similarity'  => (float) env('IMAGE_SEARCH_MIN_SIMILARITY', 0.72),
        // Also drop anything this far below the best match.
        'max_gap'         => (float) env('IMAGE_SEARCH_MAX_GAP', 0.18),
        // Soft boost for the category most of the nearest images belong to (no hard filter).
        'category_boost'  => (float) env('IMAGE_SEARCH_CATEGORY_BOOST', 0.02),
        'timeout'         => (float) env('IMAGE_EMBED_TIMEOUT', 10),
    ],
];
