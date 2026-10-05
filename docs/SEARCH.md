# Search

Search bar and "search by image" for the storefront. Three pieces:

| Piece | Where | Role |
|---|---|---|
| Meilisearch | `127.0.0.1:7700`, master key = `MEILISEARCH_KEY` | keyword search with typo tolerance + synonyms, vector search |
| Embedding service | `choosetounsi-ai-service`, `127.0.0.1:8001` | photo → CLIP vector, text → multilingual vector |
| Laravel | `app/Services/Search/` | indexing, query normalization, ranking, fallbacks |

Only Laravel talks to Meilisearch and the embedding service; the storefront calls the same
`/api/search/*` endpoints as before.

## Indexes

- **products**: one document per live product (approved, active, not deleted). Searchable, in
  order of weight: name, FR/EN/AR translated names, category + subcategory names (3 languages),
  attribute values and variant options, description with the catalog's shared template text
  removed (`BoilerplateFilter`). Optional 384-dim text vector (`embedder: text`).
- Photos are not in Meilisearch: see "Search by photo" (MySQL `image_fingerprints`).

Stock, price, ratings and sales are **not** in the index: they are read live from MySQL when
ranking, so they are never stale.

## Keeping the index current

| Change | What happens |
|---|---|
| product created / text, category or status changed / deleted / restored | Scout queues the document update (or removal) |
| photo added, replaced, deleted; product goes live/offline | `IndexProductImages` job → `image_fingerprints` (queued, one per product, 5 s delay) |
| variant added/removed/(de)activated | product document + photos refreshed |
| attribute values, category renamed, translations arrive | product document(s) refreshed |
| stock, price, views | nothing (read live) |

Text vectors are cached by content, so a rebuild doesn't re-embed unchanged products.

Commands:

```powershell
php artisan search:reindex              # Meilisearch products, full rebuild (nightly 02:30)
php artisan image-search:rebuild        # photo fingerprints (nightly 02:45)
php artisan search:sync-settings        # push resources/search/synonyms.txt + settings
```

`php artisan queue:work` must be running for live sync (see QUEUE_AND_SCHEDULER.md).

## Search bar (`ProductSearch`)

1. **Normalize** (`QueryNormalizer`): lowercase, Latin accents, Arabic ا/أ/إ/آ, ى→ي, ة→ه, ؤ, ئ,
   diacritics, tatweel, Arabic digits, leading "ال". Indexed text goes through the same function.
2. **Keyword + vector query** (one Meilisearch multi-search): keywords (1 typo from 4 letters,
   2 from 8) + the synonym file, and multilingual vector similarity. Keyword matches always
   come first; up to 6 vector-only matches with score ≥ `SEARCH_SEMANTIC_MIN_SCORE` (0.73,
   = (1 + cosine) / 2) are added after them, or shown alone when no keyword matched.
3. **Did you mean**: fewer than 3 keyword matches → words that aren't in the catalog
   vocabulary are corrected (edit distance 1, or 2 for long words) and the search runs again;
   the corrected search is kept when it matches more products.
4. **Alternatives**: still nothing → the closest products, flagged `alternatives: true`.
5. **Business signals** (`config/search.php` → `boosts`): in stock, rating (damped below
   5 reviews), 90-day sales, featured. Small weights: they reorder close matches only.
6. **Fallback**: Meilisearch down → MySQL `LIKE` search with synonym expansion (`source: "fallback"`).

Category filter is applied in Meilisearch; min/max price on the price actually paid (promotions).

Autocomplete (`/api/search/suggestions`) returns product names in the storefront language.

## Synonyms

`resources/search/synonyms.txt`: one group of equivalent words per line, any language/script.
Include the English word when products are named in English. After editing:
`php artisan search:sync-settings`. The admin panel's **Missed searches** page lists what
customers searched without results: the best source of new lines.

## Search by photo (`ImageSearch`)

Fingerprints live in MySQL (`image_fingerprints`): one 512-dim CLIP vector per product or
color photo, float32 BLOB (2 KB), L2-normalized at insert, with the model that made it
(`clip-vision-int8.onnx@<hash>`). Only photos of live products of active sellers are
searchable. `FingerprintIndex` keeps them in the Laravel cache (one blob per catalog
version); any change bumps the version, which also drops cached results.

Kept current by the `IndexProductImages` job (photo added/replaced/deleted, product goes
live/offline) and `php artisan image-search:rebuild` (nightly 02:45; `--fresh` re-embeds
everything, needed after a model change).

A search:

1. The storefront crops the photo to the item and resizes it to ≤ 512 px (JPEG).
2. AI service `/embed/query` (≈3 s timeout): mean vector of the photo, a tighter center crop
   and its mirror image, + the item's dominant color.
3. Similarity to every catalog photo (dot product); best photo per product.
4. Category detection: similarity to each category's centroid (mean of its photos; the
   finest category: subcategory, else category). Confident = best ≥ `IMAGE_SEARCH_CENTROID_MIN`
   and ahead of the second by `IMAGE_SEARCH_MARGIN_MIN`, or confirmed by the categories of
   the 5 nearest products. Confident → +`IMAGE_SEARCH_CATEGORY_BOOST` for the top 1–2
   categories, and other categories need similarity ≥ `IMAGE_SEARCH_OUTSIDE_MIN`.
5. +`IMAGE_SEARCH_VOTE_BOOST` for the category of most of the 5 nearest products,
   +up to `IMAGE_SEARCH_COLOR_BOOST` for the same dominant color (CIELAB distance).
6. Sections: **exact** = similarity ≥ `IMAGE_SEARCH_EXACT`; **similar** = score ≥
   `IMAGE_SEARCH_MIN_SCORE` and within `IMAGE_SEARCH_MAX_GAP` of the best similar one.
   Nothing → the closest products of the predicted category (`fallback: true`).
7. A color photo as best match → the card shows it and links with `?color=` (preselected).

Every search is logged in `image_search_logs` (predicted category, margin, top 5 scores,
counts, first clicked result) to tune the thresholds:

```sql
SELECT DATE(created_at) day, COUNT(*) searches, AVG(exact_count > 0) with_exact,
       AVG(fallback) fallback, AVG(clicked_product_id IS NOT NULL) clicked, AVG(clicked_rank) rank
FROM image_search_logs GROUP BY day ORDER BY day DESC;
```

Results are cached 10 min per photo (sha1); 10 searches/min per user or IP. AI service down
or slow → HTTP 503 `code: unavailable`, the camera button greys out
(`GET /api/search/image/status`), text search is untouched.

## Turning semantic search off

`SEARCH_SEMANTIC=false` in the backend (+ `TEXT_ENABLED=false` in the AI service to free
~165 MB), then `php artisan search:sync-settings`. Keyword + synonyms + typo search keeps
working; "You might also like" uses photo similarity and the content fallback.
