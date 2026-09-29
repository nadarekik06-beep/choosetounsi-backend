# Smart sponsoring — progress log

Branch `feature/smart-sponsoring` in all four repos. Source of truth for the starting point: `SPONSORING_AUDIT.md`.

| Repo | Branched from | Notes |
|---|---|---|
| backend | `feature/smart-homepage` @ `968716f` | first commit on the branch adds `SPONSORING_AUDIT.md` (`0dc82e4`) |
| storefront | `feature/smart-homepage` @ `6928379` | clean |
| admin-panel | `feature/finance-order-drawer` @ `a3d8c6a` | your uncommitted finance-drawer work was committed there first as **"WIP: finance order drawer"**, so nothing was lost; this branch includes it |
| ai-service | `feature/smart-homepage` @ `65625f2` | the regenerated FAISS indexes were committed first ("Update search indexes"); the repo had no git identity, so I set `user.name`/`user.email` locally to match the backend repo |

Phase 0 changes are **not committed yet** (waiting for your go).

---

## Phase 0 — Foundations & fixes ✅ (waiting for "go")

### What changed

**Foundations**
- `config/ads.php`: every tunable number of the ad engine under `defaults` (budgets, CPC, tier discounts, monthly credits, dedupe/attribution windows, ranking priors, per-placement relevance thresholds and density caps, popup and e-mail settings, alert thresholds). Deployment-only keys (timezone `Africa/Tunis`, token TTLs, cache TTLs, Groq model) sit outside `defaults`.
- `app/Services/Ads/AdSettings.php` (singleton): `config('ads.defaults')` overridden by `platform_settings` rows keyed `ads.<name>`.
  - Cached for 5 min and memoized per request.
  - `get('tier_click_discount.red')` dot access, plus `float()` / `int()` helpers.
  - `set([...])` rejects unknown keys and flushes the cache.
  - No endpoint yet: `GET /api/ads/config` comes with the seller APIs in Phase 1.
- Database queue:
  - `QUEUE_CONNECTION=database` in `.env` and `.env.example`. `.env.testing` stays `sync`.
  - New `failed_jobs` table; the `jobs` table already existed.
- Scheduler (`app/Console/Kernel.php`):
  - `ads:complete-ended` every 5 minutes.
  - `search:rebuild` nightly at 02:30 Africa/Tunis (01:30 UTC). The app timezone is UTC, so new daily entries pin `config('ads.timezone')`.
  - The other `ads:*` commands are added in the phase that creates them.
- `products` index `idx_products_sponsored (is_sponsored, sponsored_priority)`.

**Bug fixes** (numbers from the spec's §7)

| # | Fix | Where |
|---|---|---|
| 2 | `/api/sponsored-products` resolves the Bearer user (`$request->user() ?? $request->user('sanctum')`), so targeting now applies. The old "relaxed targeting" fallback that re-added targeted-out ads is removed. The organic backfill excludes those ads and is always returned with `is_sponsored=false`, `sponsor_data=null` (no more lazy-loaded sponsor data on backfill). | `SponsorshipController@publicFeed` |
| 3 | Wilaya targeting uses `User::targetingWilaya()`: default address, else latest order, memoized per request. New `App\Support\Wilayas` holds the 24 canonical names (same as the storefront's `lib/i18n/wilayas.ts`) and normalises loose spellings (`Kef` → `Le Kef`, `manouba` → `La Manouba`, `BEJA` → `Béja`). New targets are stored normalised. The seller wizard now uses the canonical list instead of its own misspelled copy. | `Sponsorship::matchesUser`, `User.php`, `Support/Wilayas.php`, `SponsorshipController@sponsor`, storefront `app/seller/promote/page.tsx` |
| 4 | Black Pepper direct toggle removed: `POST /api/seller/black/sponsor/{id}`, the unused `GET /api/seller/black/sponsored`, their methods, `SponsoredProductActivatedNotification` and the orphan `seller.black.sponsor_on/off` strings. | `routes/api.php`, `BlackPepperController.php` |
| 8 | Admin sponsorship search grouped inside `where(fn …)`, so `status` / `plan_type` filters can't be bypassed. | `AdminSponsorshipController@index` |
| 9 | One tier helper, `PlanGate::tierFor($sellerId)`, used by `sponsor()`, `index()` and `quota()`. It resolves the seller subscription's current plan, then the application's plan, then the tier. `meta.plan` / `data.plan` now always return `free`/`red`/`black` (before: the raw plan slug). | `PlanGate.php`, `SponsorshipController` |
| 10 | Search page "Trending now" called the non-existent `/api/sponsorships/public`. It now shows the most-viewed products (organic). | storefront `app/search/page.tsx` |
| 11 | Discover page fetches ads only for page 1 (no re-injection on every page, and those ads are removed from later organic pages). Only items with `is_sponsored=true` get the "Sponsored" label; the popularity backfill is dropped from the ad slots. | storefront `app/discover/page.tsx` |
| 13 | Navbar search dropdown "Popular Products" shows organic most-viewed products instead of unlabelled ads. Product listings no longer put sponsored products first on the guest/explicit-sort path, so `price_asc`/`price_desc`/`views`/`best_selling`/newest are honoured exactly. | storefront `Navbar.tsx`; `ProductController@index` |
| 14 | No more writes on GET: `expireOverdue()` removed from the public feed, seller list, admin stats/list and `HomeFeedBuilder`. Reads use the new `Sponsorship::live()` scope (active and not past `end_at`), so an ended ad disappears immediately even before the scheduled command flips its status. | `Sponsorship.php`, controllers, `HomeFeedBuilder`, `ProductCardPresenter` |
| 15 | Seller and admin sponsorship lists eager-load `product.primaryImage` (no per-row image query). | `SponsorshipController@index`, `AdminSponsorshipController@index` |
| 18 | AI service: binds `127.0.0.1` by default (`AI_SERVICE_HOST` env to override). Every route except `/health` requires the `X-AI-Token` header (constant-time compare). Laravel sends it on every AI call via a new `Http::ai()` macro (search text/image/suggest, chatbot retriever, similar products, `search:rebuild`). | ai-service `config.py`, `main.py`; backend `AppServiceProvider`, `config/services.php` (`services.ai.token`), 4 call sites |

**Dead code removed:** `Sponsorship::maybeResetBlackQuota()` and its calls, `app/Mail/SellerMails.php` (duplicate mailable classes, never autoloadable), storefront `sections/Hero.tsx` and `sections/RecommendedSection.tsx` (unused), the stale `ProductScoringService` docblock and the wrong route list in `SponsorshipController`. The unscheduled `sponsorships:expire` became the scheduled `ads:complete-ended`, with the old name kept as an alias.

### Files
- **backend (new):** `config/ads.php`, `app/Services/Ads/AdSettings.php`, `app/Support/Wilayas.php`, `app/Console/Commands/CompleteEndedSponsorships.php` (renamed from `ExpireSponsorships.php`), `database/migrations/2026_09_29_000001_create_failed_jobs_table.php`, `database/migrations/2026_09_29_000002_add_sponsored_index_to_products_table.php`, `tests/Feature/Ads/SponsoringFoundationsTest.php`, this file.
- **backend (changed):** `app/Console/Kernel.php`, `app/Providers/AppServiceProvider.php`, `app/Services/PlanGate.php`, `app/Models/{Sponsorship,User}.php`, `app/Http/Controllers/Api/Seller/{SponsorshipController,BlackPepperController}.php`, `app/Http/Controllers/Admin/AdminSponsorshipController.php`, `app/Http/Controllers/Api/{ProductController,SearchController}.php`, `app/Services/Recommendation/{HomeFeedBuilder,ProductCardPresenter,SimilarProductsFinder}.php`, `app/Services/Chat/ProductRetriever.php`, `app/Console/Commands/RebuildSearchIndex.php`, `app/Services/ProductScoringService.php` (comment), `config/services.php`, `routes/api.php`, `resources/lang/{en,fr,ar}/seller.php`, `.env.example`.
- **backend (deleted):** `app/Mail/SellerMails.php`, `app/Notifications/SponsoredProductActivatedNotification.php`.
- **storefront:** `app/components/layout/Navbar.tsx`, `app/search/page.tsx`, `app/discover/page.tsx`, `app/seller/promote/page.tsx`; deleted `app/components/sections/{Hero,RecommendedSection}.tsx`.
- **ai-service:** `config.py`, `main.py`, `.gitignore` (adds `.ai_token`).
- **Local, not committed (gitignored):**
  - backend `.env`: `QUEUE_CONNECTION=database`, and `AI_SERVICE_TOKEN` set to a freshly generated random secret.
  - ai-service `.ai_token`: the same secret.

### Checks run
- `php artisan migrate` on the dev DB `choosetounsi`: 2 migrations applied. A backup was taken first: `C:\xampp\backups\choosetounsi_before_phase0_20260929_1659.sql`.
- `migrate:fresh --seed` on a throwaway copy `choosetounsi_fresh` (`--env=testing`, `DB_DATABASE` overridden): ✅. I used a separate DB so neither the dev DB nor `choosetounsi_test` was wiped.
- `php artisan test`: **172 passed, 13 failed**. The 13 failures are the same pre-existing Breeze `Auth\*` tests and `ExampleTest` (web `/` returns 500) that fail on the untouched branch (baseline: 162 passed / 13 failed). The new `SponsoringFoundationsTest` has 10 tests, 44 assertions, all passing.
- AI service: existing unit tests pass (7/7). TestClient check: `/health` returns 200; `/similar` and `/index/rebuild` return 401 with no or wrong token and 200 with the right one.
- Storefront `npm run build`: ✅.
- Smoke test on the running dev API as a guest: `/api/sponsored-products`, `/api/products?sort=views`, `/api/home/feed` all return 200.
- Dev DB has 6 active sponsorship rows and **0 products flagged sponsored without a live row**, so there are no Black-toggle leftovers to reset.

### How to test manually
1. **Restart the AI service** (it now reads `.ai_token` and listens on 127.0.0.1). Search, image search and "You might also like" should work as before. A request without the header (e.g. `curl -X POST localhost:8001/similar`) should return 401.
2. **Run the queue worker** (the database queue is now on; seller-application e-mails are `ShouldQueue`): `php artisan queue:work --tries=3`. Full Windows / production instructions come in Phase 5.
3. **Scheduler:** `php artisan schedule:list` shows `ads:complete-ended` (every 5 min) and `search:rebuild` (02:30 Tunis). Run once by hand with `php artisan ads:complete-ended`.
4. **Targeting:**
   - Log in as a buyer with gender preference *male*.
   - As a seller, create a sponsorship targeted *female*.
   - The category "Trending in category" row no longer shows that ad to the male buyer, but still shows it to a logged-out visitor.
5. **Wilaya:**
   - Seller targets *Le Kef*.
   - A buyer whose default address is in *Sfax* doesn't see the ad.
   - A buyer in *Le Kef*, or with no address at all, does.
6. **Honest sorts:** on a category page pick *Price ↑*. The order is strictly by price, with no sponsored product jumping to the top.
7. **Navbar:** focus the search box. "Popular Products" shows the most-viewed products, not ads.
8. **Search page:** search for something with no results. "Trending now" shows 4 products (before: always empty because of the 404).
9. **Discover:** scroll through several pages. Ads appear only in the first batch with a "Sponsored" label and don't repeat on later pages.
10. **Ended ad:** set a sponsorship's `end_at` in the past. It disappears from the home feed and category row immediately (status still `active`) and turns `expired` at the next `ads:complete-ended`.
11. **Admin API:** `GET /api/admin/sponsorships?status=cancelled&search=<seller name>` returns only cancelled rows.

### Open questions
1. **Commit Phase 0?** I haven't committed the phase's changes. Should I make one commit per repo per phase from now on?
2. **Logged-in default listing:** the personalized scoring path (logged-in buyer, default sort) still adds `sponsored_priority` as a score bonus. Phase 2 removes it together with the recommendation boosts, as the spec schedules. Is keeping it until then OK?
3. **Queue switch now or in Phase 5?** `QUEUE_CONNECTION=database` is live now, so seller-application e-mails wait for a worker. If you'd rather not run `queue:work` during development until Phase 5, I can revert just that line in `.env`.
4. **Dropping `products.sponsored_until`:** the spec's §2.2 asks to drop it. I left it for the Phase 1 migration list so all schema changes go through your plan approval together.
5. **Also removed `GET /api/seller/black/sponsored`:** it only listed products for the removed toggle and had no caller. Tell me if you want it back as a read-only list.

**Next:** on your "go", I'll enter plan mode and show the Phase 1 migration list and the new service and class list before writing any code.
