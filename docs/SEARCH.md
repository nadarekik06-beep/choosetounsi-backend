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
- **product_images**: one document per product or variant photo with its 512-dim CLIP vector
  (`embedder: clip`); `distinctAttribute: product_id`, so a product shows up once.

Stock, price, ratings and sales are **not** in the index: they are read live from MySQL when
ranking, so they are never stale.

## Keeping the index current

| Change | What happens |
|---|---|
| product created / text, category or status changed / deleted / restored | Scout queues the document update (or removal) |
| photo added, replaced, deleted; product goes live/offline | `IndexProductImages` job (queued, one per product, 5 s delay) |
| variant added/removed/(de)activated | product document + photos refreshed |
| attribute values, category renamed, translations arrive | product document(s) refreshed |
| stock, price, views | nothing (read live) |

Photo vectors are stored in `search_image_embeddings` by file content, so each file is embedded
once and a rebuild doesn't re-embed anything. Text vectors are cached by content too.

Commands:

```powershell
php artisan search:reindex              # full rebuild from MySQL (also runs nightly at 02:30)
php artisan search:reindex --no-images  # products only
php artisan search:sync-settings        # push resources/search/synonyms.txt + settings
```

`php artisan queue:work` must be running for live sync (see QUEUE_AND_SCHEDULER.md).

## Search bar (`ProductSearch`)

1. **Normalize** (`QueryNormalizer`): lowercase, Latin accents, Arabic ا/أ/إ/آ, ى→ي, ة→ه, ؤ, ئ,
   diacritics, tatweel, Arabic digits, leading "ال". Indexed text goes through the same function.
2. **Hybrid query**: keywords (1 typo from 4 letters, 2 from 8) + the synonym file, blended
   (`SEARCH_SEMANTIC_RATIO`, default 0.35) with multilingual vector similarity. A product found
   only through vectors must reach `SEARCH_SEMANTIC_MIN_SCORE`.
3. **Did you mean**: if no keyword matched, words that aren't in the catalog vocabulary are
   corrected (edit distance 1, or 2 for long words) and the search runs again.
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

## Search by image (`ImageSearch`)

Photo → CLIP vector → nearest photos (one hit per product) → keep similarity ≥
`IMAGE_SEARCH_MIN_SIMILARITY` (0.72) and within `IMAGE_SEARCH_MAX_GAP` (0.18) of the best →
+0.02 for products in the category that dominates the 5 nearest photos (soft, no filtering).
Embedding service down → HTTP 503 with "temporarily unavailable".

## Turning semantic search off

`SEARCH_SEMANTIC=false` in the backend (+ `TEXT_ENABLED=false` in the AI service to free
~165 MB), then `php artisan search:sync-settings`. Keyword + synonyms + typo search keeps
working; "You might also like" uses photo similarity and the content fallback.
