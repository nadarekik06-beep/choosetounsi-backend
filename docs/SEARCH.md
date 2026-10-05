# Search

Search bar and "search by photo" for the storefront, all on MySQL. Two pieces:

| Piece | Where | Role |
|---|---|---|
| Laravel | `app/Services/Search/` | text index, photo fingerprints, ranking, fallbacks |
| AI service | `choosetounsi-ai-service`, `AI_SERVICE_URL` (127.0.0.1:8001) | photo → CLIP vector + dominant color (only model loaded: `clip-vision-int8`) |

Only Laravel talks to the AI service. If it is down, photo search answers "unavailable" and
nothing else changes: the search bar never calls it.

## Search bar (`ProductSearch`)

MySQL table `product_search_index` (one row of tokens per product: names in every language,
category names, attribute values / variant options, description without the catalog's shared
template text). Kept current by `SearchIndexObserver` (synchronously) and rebuilt nightly by
`php artisan search:build-index` (02:15).

1. **Tokens** (`SearchText`): lowercase, Latin accents, Arabic letter variants, plurals, stop words.
2. **Concepts**: each word (or a phrase the synonym file knows) with its synonyms, scored per
   field: name 60, category 50, name prefix 35, brand/attributes 20, description 8; a synonym
   counts 40 %. Every concept found in name / category / attributes = **direct** result,
   anything weaker = **weak** (never a best result).
3. **Did you mean**: fewer than 3 direct results → unknown words are corrected against the
   catalog vocabulary and the corrected query is kept when it finds more.
4. **Business signals** (`config/search.php` → `boosts`): in stock, rating, 90-day sales,
   featured, real photo. Small: they only reorder close scores.

Autocomplete (`/api/search/suggestions`) returns product names in the storefront language.
Searches with no/few results are logged for the admin **Missed searches** page.

## Synonyms

`resources/search/synonyms.txt`: one group of equivalent words per line, any language/script.
Include the English word when products are named in English. The file is read again
automatically when it changes. The admin panel's **Missed searches** page lists what
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

### Calibration (2026-10-05)

Catalog: 40 real photos (the demo catalog's placeholder cards are not fingerprinted). Best
photo pair per product pair: other category ≤ 0.71 (p99 0.70), same category other
subcategory median 0.68, same subcategory median 0.86 (max 0.87): hence exact ≥ 0.87.

20 test queries (`php artisan image-search:try`): 8 phone-style shots of catalog items
(skewed, uneven light, blur, JPEG, on a bed / floor / wall / cluttered scene) and 6 real
photos of other items (Wikimedia Commons), each with the default box and cropped by hand.

| Query | Predicted | Result |
|---|---|---|
| catalog items, phone-style (6) | right category, all confident | right product first; exact for the dress, set, headphones |
| catalog items on a cluttered scene, uncropped (2) | right category, not confident | fallback, right product first (0.61-0.63); cropped: 0.80-0.86 |
| real headphones (Sony) | Headphones | Casque audio 0.86 (similar) |
| real canvas sneakers on a table | Sneakers | AirForce 0.75, Nike 0.73 (similar) |
| real leather bag (no bag photo in the catalog) | Scarf, not confident | fallback: closest fashion items |
| dress / tracksuit / sneakers **worn** by a person | Jeans / SweatPants | fallback or jeans (miss) |

Cropping is what matters most; averaging 3 views raised the similarity of the right product by
+0.035 on average (single view vs photo + center + mirror). Known weak spot: clothes worn by a
person against flat product photos; more catalog photos per category (and seller photos of
worn items) improve the centroids. Re-tune with `image_search_logs` (clicked rank, fallback rate).

## Keeping the indexes current

| Change | What happens |
|---|---|
| product text / category / status, attribute values, translations | `product_search_index` row rebuilt at once |
| photo added, replaced, deleted; product goes live / offline | `IndexProductImages` job → `image_fingerprints` (queued, one per product, 5 s delay) |
| product moved to another category, seller (de)activated | cached photo index rebuilt (version bump) |
| stock, price, views | nothing (read live) |

```powershell
php artisan search:build-index          # search bar index, full rebuild (nightly 02:15)
php artisan image-search:rebuild        # photo fingerprints (nightly 02:45)
php artisan image-search:rebuild --fresh  # re-embed every photo (after changing the AI model)
```

`php artisan queue:work` must be running for photo indexing (see QUEUE_AND_SCHEDULER.md);
without it, the nightly rebuild catches up.
