# Sponsoring / Boost / Ads — Audit of the current state

_Audit date: 2026-09-29 · branch `feature/smart-homepage` @ `968716f` · read-only exploration, no code changed._

Repos covered:

| Short name | Path |
|---|---|
| backend | `C:\xampp\htdocs\choosetounsi-backend` (Laravel 8 API) |
| storefront | `C:\xampp\htdocs\choosetounsi-frontend` (Next.js App Router, contains the seller dashboard under `app/seller/`) |
| admin | `C:\xampp\htdocs\admin-panel` (Next.js) |
| ai | `C:\xampp\htdocs\choosetounsi-ai-service` (FastAPI, MiniLM + CLIP + FAISS) |

Paths below are relative to each repo root.

**TL;DR.** Two separate "sponsor" mechanisms exist and don't agree with each other:
1. the **Sponsorship** flow (`sponsorships` table, pay per day + boost surcharge, targeting, counters), and
2. the **Black Pepper toggle**, which flips `products.is_sponsored` directly with no row, no end date, no charge and no tracking.

Ranking uses only the denormalised `products.is_sponsored` / `sponsored_priority` flags. Payment is a sandbox stub that accepts any token. Impression, click and conversion counters are plain integers that can be inflated or misattributed. The admin API exists, but the admin panel has no page for it. Targeting applies only on the new `/home/feed`.

---

## 1. DATABASE

### 1.1 Sponsoring / ads tables

#### `products` (sponsor columns) — `database/migrations/2026_04_15_000001_add_sponsored_to_products_table.php`
| Column | Type | Notes |
|---|---|---|
| `is_sponsored` | boolean, default false | after `featured`. **No index.** |
| `sponsored_until` | timestamp nullable | **Never set to a non-null value** (only nulled by the Black toggle) |
| `sponsored_priority` | unsignedTinyInteger, default 0 | Copy of the highest active `sponsorships.boost_score`, or the raw 1–10 priority from the Black toggle |
| `sponsored_at` | timestamp nullable | tie-breaker in ordering |

These flags are the only thing every ranking query reads. `Sponsorship::syncProductFlags()` keeps them in sync, but not every write path calls it (see §7).

#### `sponsorships` — `database/migrations/2026_04_16_000001_create_sponsorships_table.php` (+ 2 alterations)
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `seller_id` | FK → `users.id` cascade | |
| `product_id` | FK → `products.id` cascade | |
| `plan_type` | enum `free,red,black` default `free` | plan **tier** at activation |
| `boost_score` | unsignedTinyInteger default 10 | computed priority (see §2.2) |
| `status` | enum `active,expired,cancelled` default `active`, indexed | **no `paused`/`pending` value** |
| `paused_reason` | enum `plan_downgrade,manual` nullable | added by `2026_06_03_000001_create_seller_subscriptions_table.php:177-189` |
| `paused_at` | timestamp nullable | same migration |
| `start_at`, `end_at` | timestamp nullable (both indexed) | `end_at` null = open-ended |
| `amount_charged` | decimal(10,3) default 0 | |
| `payment_reference` | string nullable | "Future Stripe / D17" — **never written** |
| `was_paid` | boolean default false | |
| `used_free_quota` | boolean default false | Black weekly free slot |
| `ai_tags` | json nullable | Groq-generated keywords |
| `ai_ad_copy` | text nullable | Groq-generated ad line |
| `target_gender` | enum `male,female,unisex` nullable | `2026_05_18_000002_add_targeting_to_sponsorships_table.php` |
| `target_wilaya_ids` | json nullable | wilaya **names**, not IDs |
| `target_category_ids` | json nullable | category IDs |
| `target_price_min`, `target_price_max` | decimal(10,3) nullable | |
| `impressions`, `clicks`, `conversions` | unsignedInteger default 0 | aggregate counters only, no event log |
| `created_at`, `updated_at` | | |

Indexes: `(seller_id,status)`, `(product_id,status)`, `(plan_type,status)`, `start_at`, `end_at`, `status`. There is no unique "one active per product" constraint (the migration comment says it is enforced in code).

Model: `app/Models/Sponsorship.php`. `belongsTo` seller (`User`, `seller_id`) and `product`. `Product::sponsorships()` is hasMany and `Product::activeSponsorship()` is hasOne (`app/Models/Product.php:165-200`).

#### `promotions` + `promotion_products` — `2026_05_20_000001/2`
This is the **price-discount** system (flash sales and discounts). It is not paid visibility, but it shares the name.
- `promotions`: `seller_id` FK users, `name`, `type` enum `flash_sale,discount`, `discount_type` enum `percentage,fixed`, `discount_value` decimal(10,3), `starts_at`/`ends_at` datetime, `flash_stock`, `flash_stock_used`, `status` enum `scheduled,active,paused,expired`, `priority` tinyint, plus `paused_reason`/`paused_at` (added in the seller_subscriptions migration). Indexes: `idx_promotions_active_window(status,starts_at,ends_at)`, `idx_promotions_seller_type`, `idx_promotions_expiry`, `idx_promotions_activation`.
- `promotion_products`: `promotion_id` FK cascade, `product_id` FK cascade, unique `(promotion_id,product_id)`, index on each FK.

#### `vip_requests` — `2026_04_15_000002_create_vip_requests_table.php`
`user_id` FK, `type` enum `reel,promotion,support`, `status` enum `pending,in_progress,completed,rejected`, `message`, `admin_note`, `handled_by` FK users, `handled_at`. Black Pepper sellers use it to ask for **manual** promotion ("featured placement on homepage & socials"). Nothing automates what an admin does with it.

#### `subscription_plans` — `2026_09_24_100001_create_subscription_plans_table.php`
Relevant columns: `tier` (0 green, 1 red, 2 black; this drives sponsorship pricing), `max_sponsored_products` smallint nullable (null = unlimited), `features` json containing a `sponsorships` bool. All three seeded plans have `sponsorships: true` and `max_sponsored_products: null`. The same migration creates `platform_settings (key unique, value json)`, which could hold ad pricing but doesn't today.

#### Ad wallet / credits / ad payments
- **No ad wallet, ad credits or ad invoice table exists.**
- `users.wallet_balance` decimal(12,3) and `wallet_transactions` (`2026_04_02_000004/5`) exist for **buyer** order payments. `reason` enum is `order_payment, order_refund, admin_top_up, manual_debit`. There is no ad/sponsorship reason, and sponsorship does not use the wallet.
- `orders.payment_method` enum `cod,card,d17,wallet` plus `stripe_payment_intent_id` and `d17_reference`. These apply to orders only.

### 1.2 Behaviour-tracking tables

| Table | Migration | What's logged | When / by whom |
|---|---|---|---|
| `user_interactions` (current, canonical) | `2026_09_28_000001` | `user_id` nullable FK, `session_id` char(36) (guest UUID from `X-Session-Id`), `product_id` FK nullable, `seller_id`, `category_id`, `event_type` enum `view, click, cart_add, favorite_add, favorite_remove, purchase, search, follow, unfollow`, `source_section` varchar(40), `search_query` varchar(191), `order_id`, `created_at`. Indexes: `(user_id,created_at)`, `(session_id,created_at)`, `(created_at,event_type,product_id)`, `(product_id,event_type)` | Via `InteractionTracker::record()` (`app/Services/Recommendation/InteractionTracker.php:52-100`). **view/click**: storefront `POST /api/track` (only `config('recommendations.client_events') = ['view','click']`). **cart_add**: `CartController.php:170`. **favorite_add/remove**: `FavoriteController.php:60,70`. **follow/unfollow**: `SellerFollowController.php:33`. **search**: `SearchController.php:514`. **purchase**: `CheckoutController.php:451,455,684` (at order creation). Deduped via `Cache::add` (view 30 min, click 5 min per section, search 60 s). Guest rows are merged into the account on `POST /api/track/merge`. Pruned after 365 days (`recommendations:prune`). |
| `user_interest_profiles` | `2026_09_28_000002` | `user_id` unique / `session_id` unique, `profile` json (category/subcategory/seller/brand/price affinities), `signal_count`, `computed_at` | Rebuilt lazily when the actor is "dirty", or daily at 03:30 (`recommendations:refresh-profiles`) |
| `user_activity_logs` (legacy) | `2026_04_27_000003` (+ enum fix `2026_05_26_000002`) | `user_id, product_id, category_id, action enum(view,favorite,cart,order,purchase), session_id, created_at` | **No longer written.** Copied into `user_interactions` by that migration. `UserPreferenceService::logActivity()` now forwards to the tracker. `ProductScoringService.php:27` docblock still says it reads this table (stale comment; the service reads `user_interactions`). |
| `user_preferences` | `2026_04_27_000002` | `user_id` unique, `gender` enum, `category_ids` json, `brand_ids` json, `price_min`, `price_max` | Explicit onboarding preferences. Used by sponsorship targeting (`matchesUser`) and the legacy `ProductScoringService`. |
| `products.views` | — | integer counter | `ProductController@show` (`app/Http/Controllers/Api/ProductController.php:255-261`), once per viewer per 30 min (cache key) |
| `favorites` | `2024_01_01_000081` | `user_id, product_id`, unique pair, timestamps | Favourite add/remove |
| `seller_follows` | `2026_09_22_000002` | `user_id, seller_id`, unique pair, index `seller_id` | Follow toggle |
| `carts` | `2024_01_01_000080` (+ variant/pack columns) | `user_id, product_id, quantity` | Current cart only. **No cart_remove or checkout-abandon event.** |
| `orders`, `order_items`, `seller_orders` | 2024/2025 migrations | Full order data; `orders.wilaya` exists | Checkout |
| `notifications` | `2024_01_01_000003` | Laravel DB notifications (uuid, morphs, data, read_at) | Various notification classes |

**Search history:** there is no dedicated table. Searches are stored as `user_interactions` rows with `event_type='search'` and `search_query`.

### 1.3 Notification / e-mail preference fields
- **None.** No `newsletter`, `marketing_opt_in`, consent, or per-channel notification preference column exists on `users` or any other table.
- `users.locale` exists (`2026_09_25_100000`). It is written by the `SetLocale` middleware from `Accept-Language`.
- `users` has **no `wilaya` column**. Wilaya lives only on `user_addresses.wilaya` and `orders.wilaya` (this matters for targeting; see §7).

---

## 2. BACKEND (Laravel)

### 2.1 Inventory

| Kind | File | Role |
|---|---|---|
| Model | `app/Models/Sponsorship.php` | constants, `matchesUser()`, quota helpers, `syncProductFlags()`, `expireOverdue()` |
| Model | `app/Models/Promotion.php`, `app/Models/SubscriptionPlan.php` | discount promos; plan tier/limits |
| Controller | `app/Http/Controllers/Api/Seller/SponsorshipController.php` | seller CRUD, public feed, impression/click |
| Controller | `app/Http/Controllers/Api/Seller/BlackPepperController.php:861-953` | Black toggle + list |
| Controller | `app/Http/Controllers/Admin/AdminSponsorshipController.php` | admin stats/list/cancel/boost |
| Controller | `app/Http/Controllers/Admin/RecommendationDebugController.php` | explains the home feed (incl. sponsored picks) |
| Controller | `app/Http/Controllers/Api/HomeFeedController.php`, `TrackingController.php` | personalized homepage + signal intake |
| Controller | `app/Http/Controllers/Api/ProductController.php`, `ProductRecommendationController.php` | listings/recs that order by sponsor flags |
| Service | `app/Services/PlanGate.php:95-115` | `canSponsor()` feature + max-count gate |
| Service | `app/Services/PlanDowngradeService.php:120-167` | pause/resume sponsorships on plan change |
| Service | `app/Services/AutoPromotionService.php` | Black hub "you should sponsor X" suggestions |
| Service | `app/Services/ProductScoringService.php` | legacy scorer, adds `sponsored_priority` as bonus |
| Service | `app/Services/Recommendation/*` | `HomeFeedBuilder`, `CandidatePools`, `InterestProfileService`, `InteractionTracker`, `SimilarProductsFinder`, `ProductCardPresenter` |
| Service | `app/Services/PromotionService.php` | effective price for discounts |
| Observer | `app/Observers/OrderObserver.php` | sponsorship **conversions** |
| Command | `app/Console/Commands/ExpireSponsorships.php` (`sponsorships:expire`) | **exists but is NOT scheduled** |
| Command | `SyncPromotionStatuses` (`promotions:sync`) | scheduled every minute (discount promos) |
| Command | `BlackDailyNotify` (`black:daily-notify`) | daily 08:00; includes auto-promo nudges |
| Command | `DemoCatalog.php:280-290` | seeds 5 demo sponsorships |
| Notification | `app/Notifications/SponsoredProductActivatedNotification.php` | DB notification to admins, **sent only by the Black toggle**, not by the paid flow |
| Events/Listeners/Jobs | — | **none related to sponsoring**; there is no `app/Jobs` directory |

### 2.2 Routes (`routes/api.php`)

| Method | URL | Handler | Auth |
|---|---|---|---|
| GET | `/api/sponsored-products` | `SponsorshipController@publicFeed` | public (l.129) |
| POST | `/api/sponsorships/{id}/impression` | `@recordImpression` | **public, no throttle** (l.130) |
| POST | `/api/sponsorships/{id}/click` | `@recordClick` | **public, no throttle** (l.131) |
| GET | `/api/seller/sponsorships/quota` | `@quota` | sanctum (l.305) |
| GET | `/api/seller/sponsorships` | `@index` | sanctum |
| POST | `/api/seller/sponsorships/sponsor` | `@sponsor` | sanctum |
| DELETE | `/api/seller/sponsorships/{id}/cancel` | `@cancel` | sanctum |
| GET | `/api/seller/black/sponsored` | `BlackPepperController@sponsoredProducts` | sanctum + `seller.feature:black_hub` (l.257) |
| POST | `/api/seller/black/sponsor/{id}` | `BlackPepperController@toggleSponsorship` | same (l.258) |
| GET | `/api/seller/black/auto-promote-suggestions` | `@autoPromote` | same |
| GET/POST | `/api/seller/black/vip-request(s)` | VIP requests | same |
| GET | `/api/admin/sponsorships/stats` | `AdminSponsorshipController@stats` | admin (l.529) |
| GET | `/api/admin/sponsorships` | `@index` | admin |
| PATCH | `/api/admin/sponsorships/{id}/cancel` | `@cancel` | admin |
| PATCH | `/api/admin/sponsorships/{id}/boost` | `@boost` | admin |
| GET | `/api/home/feed` | `HomeFeedController@index` | public (optional bearer, `X-Session-Id`) |
| POST | `/api/track` | `TrackingController@store` | public, `throttle:120,1` |
| POST | `/api/track/merge` | `TrackingController@merge` | sanctum |
| GET | `/api/admin/recommendations/debug` | `RecommendationDebugController@show` | admin |
| GET | `/api/recommendations`, `/api/products/{slug}/{similar,complementary,from-seller,recommended}` | `ProductRecommendationController` | public |

Note: the controller docblock (`SponsorshipController.php:44-45`) documents `/api/seller/sponsorships/{id}/impression|click`, but the real routes are the public ones above.

### 2.3 How a seller creates a boost — `SponsorshipController@sponsor` (l.63-269)

**Inputs** (l.65-79): `product_id` (required, must be the seller's own), `duration_days` 1–90 (default 7), `priority` 1–10 (default 5), `target_gender`, `target_wilaya_ids[]` (strings), `target_category_ids[]`, `target_price_min`, `target_price_max` (must be > min), `payment_method` `card|free_quota`, `payment_token`.

**Checks:** approved seller application; resolves plan **tier** via `SubscriptionPlan::forSlug(...)->tierKey()` (l.90); `PlanGate::canSponsor` (feature flag plus `max_sponsored_products`, counting both sponsorship rows and `is_sponsored` products); product must be `is_approved && is_active`; no other active sponsorship for the product.

**Pricing: per day + one-off boost surcharge.** Constants live in `Sponsorship.php:67-79` and `SponsorshipController.php:56-57`:
```php
BOOST = ['free' => 10, 'red' => 30, 'black' => 70];
PRICE = ['free' => 5.000, 'red' => 2.000, 'black' => 0.000];   // DT/day
BLACK_FREE_PER_WEEK = 3;
BOOST_FREE_THRESHOLD = 5; BOOST_SURCHARGE_PER_POINT = 5.000;  // DT

$finalPriority  = max(1, round(BOOST[$plan] * ($manualPriority / 10)));   // l.128-131
$boostExtraCost = max(0, $priority - 5) * 5.000;                        // l.523-527
```
| Tier | Base | Surcharge | Total |
|---|---|---|---|
| Green (`free`) | 5.000 DT × days | + surcharge | `5×days + surcharge` |
| Red | 2.000 DT × days | + surcharge | `2×days + surcharge` |
| Black, quota left | 0 (**for any duration up to 90 days**) | surcharge only | l.149-166 |
| Black, quota used | 1.500 DT × days (**hard-coded at l.170, not in `PRICE`**) | + surcharge | l.168-180 |

The resulting `boost_score` is 1–10 for Green, 3–30 for Red and 7–70 for Black. The same slider value therefore gives a Black seller 7× the ranking weight of a Green seller.

**Payment** (`processPayment`, l.542-567): **sandbox stub.** An amount ≤ 0 passes. Otherwise any non-empty `payment_token` is accepted and only logged (`"[Payment] Sandbox charge accepted"`). A missing token returns HTTP 402 `PAYMENT_REQUIRED` with `amount_due`. There is no Stripe/D17/Flouci/Konnect call and no wallet debit, and `payment_reference` is never stored. The storefront "tokenises" a card by base64-encoding `{last4, exp, holder}` (`lib/sponsorshipApi.ts:224-233`).

**AI content:** Groq `llama3-8b-8192` generates `ai_tags` and `ai_ad_copy` synchronously inside the request (12 s timeout, l.588-640). There is a template fallback.

**Persistence** (l.217-250): creates the row with `status='active'`, `start_at=now`, `end_at=now+days`, then `syncProductFlags()`. The create array also passes `boost_extra_cost`, `payment_status` and `payment_method`. **These columns don't exist and aren't in `$fillable`, so Eloquent silently drops them.** The API response still echoes them.

**Lifecycle / statuses:** `active → expired` (end_at passed) or `active → cancelled` (seller or admin). A plan downgrade sets `status='expired'` plus `paused_reason='plan_downgrade'` (`PlanDowngradeService.php:126-150`), and reactivation sets it back to `active` if `end_at` is still in the future (l.152-167). There is no pending/awaiting-payment/approval state, no refund on cancel, and no seller notification on expiry.

**Expiry:** `Sponsorship::expireOverdue()` (`Sponsorship.php:240-253`) loads overdue active rows, updates each one, and resyncs product flags. It runs **lazily** inside `publicFeed`, seller `index`, admin `stats`/`index`, and `HomeFeedBuilder::sponsoredIds`. The `sponsorships:expire` command exists but **is not in `app/Console/Kernel.php`'s schedule** (the schedule only has `subscriptions:process`, `promotions:sync`, `recommendations:*` and `black:daily-notify`).

**Black Pepper toggle** (`BlackPepperController@toggleSponsorship`, l.861-915) is a second, independent path. It sets `products.is_sponsored=true, sponsored_priority=<1..10>, sponsored_at=now` **without creating a `sponsorships` row**. That means no charge, no end date, no targeting, no counters, and no quota consumption. It notifies all admins. No storefront code calls it today (grep found no reference to `black/sponsor`), but the route is live.

### 2.4 Selection & ranking for buyers

Every surface ranks on `products.is_sponsored DESC, sponsored_priority DESC`. Only two places look at the `sponsorships` row for targeting.

**a) `GET /api/sponsored-products`** (`SponsorshipController@publicFeed`, l.392-498):
```php
$query = Product::available()->where('is_sponsored', true)
    ->with([..., 'sponsorships' => fn($q) => $q->where('status','active')->select(...targeting...)])
    ->orderByDesc('sponsored_priority')->orderByDesc('sponsored_at');
if ($catSlug) $query->whereHas('category', fn($q) => $q->where('slug', $catSlug));
$allSponsored = $query->take(100)->get();
$targeted = $allSponsored->filter(fn($p) => !$p->sponsorships->first()
                                         || $p->sponsorships->first()->matchesUser($user, $prefs));
// < min_results (default 2): relax targeting, then backfill with NON-sponsored products ordered by views
```
- It uses `$request->user()` on a route without `auth:sanctum`. The default guard can't see bearer tokens (the tracker's own comment at `InteractionTracker.php:28-30` notes this), so **`$user` is always null and targeting is never applied here**. Everyone is treated as a guest.
- Products flagged by the Black toggle (no row) always pass the filter.
- Backfilled organic products are returned in the same array with `is_sponsored=false`.

**b) `/api/home/feed`** (`HomeFeedBuilder`, `app/Services/Recommendation/HomeFeedBuilder.php`):
- `sponsoredIds()` (l.424-452) takes candidates from the cached pool (`is_sponsored`, not excluded or purchased, appearance budget left), orders by `sponsored_priority, sponsored_at`, and **keeps only products that have an active `sponsorships` row whose `matchesUser()` passes**. The actor is resolved via the sanctum guard, so targeting does work here. Black-toggle products are excluded from this row.
- Claim order (l.110-121): if ≥ `min_section_size` (4; 2 on small catalogs), a dedicated `sponsored` row (up to 16) is built and claimed **before** Recommended. Otherwise up to 2 are injected into the first organic row at slots 1 and 5 (`injectSponsored`, l.644-665). Display order puts `sponsored` 2nd, after `recommended` (l.40-43).
- Only paid placements carry `is_sponsored=true, placement='sponsored', sponsor_data` in the response (`render`, l.667-695).
- There is **no relevance scoring** of ads against the interest profile. Ranking is purely `sponsored_priority`, then recency.

**c) Product listings** (`ProductController@index`, l.113-160): the guest/explicit-sort path always prepends `orderByDesc('is_sponsored')->orderByDesc('sponsored_priority')` before the chosen sort (so even `price_asc` puts sponsored first). The authenticated default path takes the top 200 by sponsor flags and then runs `ProductScoringService::scoreAndSort`, which **adds `sponsored_priority` (up to 70) as a score bonus** (`ProductScoringService.php:262-272`). Fallback queries also order by `is_sponsored`.

**d) Recommendations** (`ProductRecommendationController`): `feed` (l.27-100) includes all sponsored products in the candidate set and orders by `is_sponsored`. `fromSeller` (l.~202), `complementary` (l.~261) and `recommended` (l.300-309, `orWhere('is_sponsored', true)`) all boost sponsored items.

**Targeting logic** (`Sponsorship::matchesUser`, `Sponsorship.php:125-188`) is a hard filter per field; a null field means no restriction. Guests match everything. Gender and category are compared against `user_preferences`. Price ranges must overlap with `user_preferences.price_min/max`. Wilaya is compared against `$user->wilaya`, **which isn't a column, so it is always null and wilaya targeting never excludes anyone.** The user's inferred interest profile (`user_interest_profiles`) and address/order wilaya are not used.

**Search:** `SearchController` (AI text/image search) does **not** consider sponsorship. There are no sponsored results in search.

### 2.5 Impression / click / conversion tracking
- **Impressions and clicks:** `recordImpression`/`recordClick` (l.503-517) run `Sponsorship::where('id',$id)->where('status','active')->increment(...)`. They are public, unauthenticated, not throttled, and not deduplicated. They store no actor, placement, timestamp or event row, so there's no per-day series and no way to audit or filter bots.
- **Conversions:** `OrderObserver::updated` (l.29-93). When an order's `status` becomes `completed` or `delivered`, it increments `conversions` on **every** sponsorship for any product in the order that was active, or active at order time. There is **no click-through attribution**: a buyer who never saw the ad still counts. It counts +1 per order (not per unit or revenue). An order that goes through `delivered` and then `completed` increments twice. Cancellations and refunds don't decrement.
- **Organic tracking** (`user_interactions`) is separate. Home-feed card clicks are sent as `click` with `source_section` (e.g. `sponsored`), so sponsored-row clicks exist there as generic interactions, but they aren't linked to a `sponsorship_id`.
- CTR and conversion rate are computed as accessors (`Sponsorship.php:262-272`) and client-side.

### 2.6 Subscription tiers (Green / Red / Black Pepper)
- **Price per day:** Green 5 DT, Red 2 DT, Black 3 free/week, then 1.5 DT/day.
- **Boost multiplier:** base 10/30/70 × slider/10 (§2.3).
- **Gate:** `features.sponsorships` and `max_sponsored_products` (`PlanGate::canSponsor`). All seeded plans allow sponsorships with unlimited count.
- **Downgrade/expiry:** pauses sponsorships (`PlanDowngradeService`, `SubscriptionService.php:339-373`).
- **Black-only:** hub toggle, auto-promote suggestions (`AutoPromotionService`, which estimates +35% visibility uplift from sponsoring trending products), VIP "promotion" requests, daily smart notifications.
- **Organic effect** (not paid): `HomeFeedBuilder::planScore` (l.711-718) gives black 1.0, red 0.6 and free 0.2 × `seller_plan_weight 0.05` as a tie-breaker in Recommended. `ProductScoringService` gives +40/+25/+10 by tier.
- Inconsistency: `sponsor()` resolves the tier via `tierKey()`, but `index()` (l.333-336) and `quota()` (l.358-361) use the raw `seller_applications.plan` slug. For admin-created plans (custom slugs) the dashboard shows the wrong plan and no Black quota.

### 2.7 Homepage personalization (reusable)
- **Endpoint:** `GET /api/home/feed`. The actor is the sanctum user or the `X-Session-Id` guest UUID. The response is cached 120 s per actor, 5-min rotation bucket, dirty-marker and locale (`HomeFeedController.php:25-40`).
- **Signals** (`config/recommendations.php`): weights purchase 5, cart_add 4, favorite_add 4, follow 4, click 2, view 1, search 1, favorite_remove −2, unfollow −3. Half-life 14 days, window 120 days, profile TTL 30 min.
- **Interest profile** (`InterestProfileService`): per actor, the decayed affinity to category (0.30), subcategory (0.25), seller (0.20), brand (0.10) and a log-price distribution (0.15), each normalized 0..1. It is persisted in `user_interest_profiles`. `scoreProduct($profile, $product, $brand)` returns `{score, parts}` and is **directly reusable for ad relevance**.
- **Candidate pool** (`CandidatePools::get`): all eligible products with category, subcategory, seller, price, views, created_ts, is_sponsored; 7-day trend (capped per actor); popularity; Bayesian rating; brand; seller plan; categories; reserved IDs. Cached 10 min in `reco:pools:v2`.
- **Rows:** recently_viewed, favorites, sponsored, recommended (0.70 affinity + 0.15 popularity + 0.10 quality + plan tie-breaker, seen ×0.3, 15% exploration), favorite_sellers, similar (AI), trending, best_sellers, new_arrivals, top_rated, popular_in_category (cold). There are per-row seller/category caps and a seeded jitter.
- **"Similar":** `SimilarProductsFinder` = 0.65 × AI `/similar` + 0.35 × content similarity, with a fallback when the AI service is down (`reco:ai_similar_down` flag).
- **Explainability:** `build($u, $s, explain: true)` via `/api/admin/recommendations/debug`.

### 2.8 E-mail / notification infrastructure
- **Mail:** `MAIL_MAILER=smtp` via the Brevo SMTP relay (`smtp-relay.brevo.com:587`, TLS), from `choosetounsi@gmail.com` / "ChooseTounsi". Credentials are in `.env` and are not reproduced here.
- **Queue:** `QUEUE_CONNECTION=sync`, so the mailables marked `ShouldQueue` actually send inline during the request. There is no worker and no `jobs` table usage. `BROADCAST_DRIVER=log`.
- **Mailables** (`app/Mail/`): `PasswordResetMail`, `SellerApplicationApprovedMail`, `SellerApplicationSubmittedMail`, `SellerMails` (a duplicate definition of the previous two), `VerificationCodeMail`, `WelcomeUserMail`. Templates are in `resources/views/emails/{auth,layout,seller-acceptance,seller-application,verification,welcome}`.
- **Notifications** (`app/Notifications/`, 18 classes). Mostly the `database` channel. `ComplaintCreated`, `ComplaintStatusChanged`, `RefundCompleted` and `SellerRejectedComplaint` also use `mail`. Sponsor-related: `SponsoredProductActivatedNotification` (DB → admins, Black toggle only) and `BlackSmartNotification` (daily Black nudges).
- **Client notification API:** `GET /api/notifications`, `/unread-count`, `PATCH /read-all`, `/{id}/read`. The admin has equivalent endpoints.
- **Missing:** marketing or newsletter mailables, unsubscribe links or tokens, consent storage, and any sponsorship lifecycle mail (activated, expiring soon, expired, weekly report).

---

## 3. STOREFRONT (Next.js — `choosetounsi-frontend`)

### 3.1 Where sponsored products appear

| Surface | File | Data source | Label / highlight | Impression | Click |
|---|---|---|---|---|---|
| Homepage personalized feed | `app/page.tsx` → `app/components/home/HomeFeed.tsx`, `FeedSection.tsx`, `FeedProductCard.tsx` | `/api/home/feed` (`lib/homeFeedApi.ts`) | "Sponsored" label only when `placement==='sponsored' && is_sponsored` (`FeedProductCard.tsx:63`) | ✅ IntersectionObserver ≥50% visible, once per mount (l.71-83) | ✅ `trackClick(product.id, section)` + `recordClick(sponsor_data.id)` (l.98-101) |
| Category page, "trending in category" row | `app/category/[slug]/page.tsx:758-766` → `app/components/SponsoredProductsSection.tsx` | `/api/sponsored-products?category_slug=` limit 4 | Gold "Sponsored" pill if `is_sponsored` and not a flash sale (`SponsoredProductsSection.tsx:166-167`); section titled "Trending in category"; `ai_ad_copy` shown (l.263) | ⚠ on component mount, not viewport (l.43-48) | ✅ (l.52-54) |
| Category grid (organic listing) | `app/category/[slug]/page.tsx:236-245` | `/api/products` (sponsored sorted first) | "⭐ Sponsored" badge | ❌ | ❌ |
| Discover page | `app/discover/page.tsx:580-630` | `publicFeed({limit:12})` + `/api/products` | One sponsored item every `SPONSORED_EVERY` organic items, labelled "Sponsored" | ❌ | ✅ `recordClick` (l.310) |
| Navbar search dropdown ("Popular Products") | `app/components/layout/Navbar.tsx:214-350` | `publicFeed({limit:4})` | **No Sponsored label.** Presented as "Popular Products". | ❌ | ❌ |
| Search results page ("Trending now") | `app/search/page.tsx:321-332` | `GET /api/sponsorships/public?limit=4` — **route doesn't exist (404)**, so the block silently stays empty | — | ❌ | ❌ |
| Product page recommendations | `app/components/ProductRecommendations.tsx` (used by `app/products/[slug]/page.tsx`) | `/api/products/{slug}/{similar,complementary,from-seller,recommended}` | "⭐ Sponsored" pill (l.151-155) | ❌ | ❌ |
| Cart drawer | `components/CartDrawer.tsx:106-126` | cart items | `SponsoredBadge compact` if `is_sponsored` (the backend cart payload doesn't set it; no `is_sponsored` in `CartController`) | ❌ | ❌ |
| Hero (legacy) | `app/components/sections/Hero.tsx:640-650` | `SponsoredProductsSection` "🔥 Trending Now" | — | — | — | **Dead:** `Hero.tsx` is imported nowhere |
| RecommendedSection (legacy) | `app/components/sections/RecommendedSection.tsx:195-230` | — | Deliberately **disguises** sponsored items as "Hot"/"Trending"/"Popular" ("never reveals 'Sponsored' to customers"). **Dead:** not imported anywhere. |

Shared pieces: `app/components/SponsoredBadge.tsx` (gold pill, `compact` variant) and `lib/sponsorshipApi.ts` (API + client-side pricing constants that duplicate the backend).

### 3.2 Popup / modal / banner on page entry
- **No ad or promotional entry popup system exists.**
- Global overlays mounted in `app/layout.tsx:71-72`: `SupportChatWidget` and `ReviewPromptPopup` (asks for reviews of delivered orders, via `/api/client/reviews/prompts`). These show the only existing "popup on entry" pattern.
- Other modals are action-triggered: `ComplaintModal`, `SellerApplicationModal`, `ReviewSubmitModal`, and the onboarding flow at `app/onboarding/`.
- There is no newsletter sign-up in the footer.

### 3.3 How signals reach the backend
- **Personalization:** `lib/tracking.ts` creates a guest UUID in `localStorage.ct_sid`, sent as `X-Session-Id`. It batches `view`/`click` events (flush every 1.5 s, max 20, `keepalive`), dedupes client-side, and posts to `/api/track`. `trackView` is called on the product page (`app/products/[slug]/page.tsx:401`); `trackClick` is called from home-feed cards.
- **Sponsorship counters:** `sponsorshipApi.recordImpression/recordClick` are fire-and-forget POSTs with no auth and no session header (`lib/sponsorshipApi.ts:312-326`).

---

## 4. SELLER DASHBOARD (inside the storefront repo)

Navigation: `app/seller/components/Sidebar.tsx:34` → `/seller/promote` ("promote", Megaphone icon). The Black hub quick action is at `app/seller/black/page.tsx:407`.

### `/seller/promote` — `app/seller/promote/page.tsx` (1 232 lines)
- **Step flow:** pick an approved and active product (fetches `/api/seller/products?is_approved=true&is_active=true&per_page=100`, l.612) → duration (`DURATIONS` 3/7/14/30 days, "7 = popular", l.62-67) → priority slider 1–10 with surcharge line (l.995-1019) → optional targeting (gender, wilaya chips from a hard-coded `TUNISIAN_WILAYAS` list at l.77, categories, price min/max) → summary → pay.
- **Ad preview:** `AdPreviewCard` (l.164).
- **Cost:** computed client-side with `calcTotalCost()` (`lib/sponsorshipApi.ts:175-212`) from duplicated constants (`SPONSOR_PRICES` includes black 1.5).
- **PaymentModal** (l.250-555): a raw card form (number with Luhn check, expiry, **CVV**, holder). In dev it pre-fills test Visa `4111…` (l.93-105). `tokeniseCard()` base64-encodes `{last4, exp, holder}`, which is what gets sent as `payment_token`.
- **Active campaigns list:** `sponsorshipApi.list({status:'active'})`. Shows impressions and clicks per campaign plus the end date (l.930) and a cancel button.
- **Black quota:** `sponsorshipApi.quota()`.

### `/seller/promote/analytics` — `app/seller/promote/analytics/page.tsx` (184 lines)
- Fetches all sponsorships (`list({per_page:100})`).
- **KPI tiles:** total impressions, clicks, conversions, average CTR (l.52-61).
- **Table:** product, plan, status, boost, period, impressions, clicks, CTR, conversions, cost (l.134-175).
- No time series, no spend-vs-revenue/ROAS, no per-placement breakdown, and no charts. The data model can't support these (aggregate counters only).

### Black Pepper hub
- `app/components/seller/black/SmartPromoteCard.tsx`: uses `/api/seller/black/auto-promote-suggestions`, showing trending, not-yet-sponsored products with an estimated TND boost. The CTA links to `/seller/promote`.
- `BlackPepperHub.tsx:713`: VIP "Request Promotion" (manual, admin-handled).
- The hub does **not** call the `black/sponsor/{id}` toggle.

---

## 5. ADMIN PANEL (`admin-panel`)

- **There is no sponsorships page.** `app/(dashboard)/` contains brand-products, categories, complaints, finance, orders, packs, product-changes, product-update-requests, products, reviews, seller-applications, sellers, statistics, subscriptions, users, vip-requests. Nothing calls `/api/admin/sponsorships*`: the backend stats/list/cancel/boost endpoints have **no UI**.
- **Plan settings:** `subscriptions/_components/PlansManager.tsx:80,173,198,292` edits `max_sponsored_products` ("Max sponsored at once") per plan and the `sponsorships` feature toggle. This is the only admin control over sponsoring.
- **Product review:** `products/[id]/_components/SummaryPanel.tsx:88-90` shows a "Sponsored" tag.
- **VIP requests:** `vip-requests/page.tsx` handles `type=promotion` requests manually (approve, complete, reject, note).
- **Missing:** ad approval or moderation queue (ads go live instantly), editable pricing (all hard-coded), ad revenue in the finance dashboard (`FinanceController` has no sponsorship figures; the only revenue number is `AdminSponsorshipController@stats.total_revenue = SUM(amount_charged WHERE was_paid)`, which comes from sandbox charges).

---

## 6. AI MICROSERVICE (`choosetounsi-ai-service`)

FastAPI on `0.0.0.0:8001` (`config.py:173`, `main.py`). Models: `sentence-transformers/all-MiniLM-L6-v2` (384-d text) and CLIP ViT-B/32 (512-d image). Indexes are `faiss.IndexFlatIP` on L2-normalized vectors (cosine), stored in `indexes/{text,image}_index.faiss` with `*_map.json` (row → product_id).

| Endpoint | Purpose | Laravel caller |
|---|---|---|
| `GET /health` | index sizes | — |
| `POST /search/text` `{query, limit}` | semantic + fuzzy search → `[{product_id, score}]`, `corrected_query` | `SearchController::searchText`, `Services/Chat/ProductRetriever.php:238` |
| `GET /search/suggest?q=` | autocomplete | `SearchController::suggestions` |
| `POST /search/image` (multipart) | CLIP image search | `SearchController::searchImage` |
| `POST /similar` `{seeds:{product_id: weight}, exclude_ids, limit≤SIMILAR_MAX_LIMIT}` | **product-to-product (multi-seed) similarity**: `score(p) = max_s w_s·sim(s,p)`, sim = text cosine, or `TEXT_W·text + IMAGE_W·rescaled image` when both have images (`similarity.py`) → `[{product_id, score, seed_id}]` | `Services/Recommendation/SimilarProductsFinder.php:83` (timeout `recommendations.ai.similar_timeout` = 2 s, down-flag 2 min, cached 30 min) |
| `POST /index/rebuild` | rebuild both indexes + vocab from MySQL | `php artisan search:rebuild` (`RebuildSearchIndex.php:80`) — **not scheduled** |

Base URL: `config('services.ai.url')` = `env('AI_SERVICE_URL', 'http://localhost:8001')` (`config/services.php:38`).

Reusable for ads:
- **User→product:** pass the user's recent or strong interactions as `seeds` to `/similar` (this is how the home "similar" row works). You can then intersect the result with the active sponsored IDs.
- **Product→product** (sponsored products similar to the one being viewed or in the cart): the same endpoint with a single seed.
- No endpoint returns raw embeddings, a user-embedding, or scores for a **given candidate list**. `/similar` ranks the whole catalog and relies on `exclude_ids`.
- Products added after the last rebuild have no vectors (Laravel falls back to content similarity).

---

## 7. GAPS AND PROBLEMS

### Correctness bugs
1. **Silent data loss on create:** `SponsorshipController.php:232-235` writes `boost_extra_cost`, `payment_status` and `payment_method`, but these are neither columns nor in `$fillable`, so they are dropped. The frontend type `SponsorshipRecord` expects them, and they come back `undefined` from `list`.
2. **Targeting is ignored on `/api/sponsored-products`:** `$request->user()` is null on a public route (`SponsorshipController.php:400`). This affects the category row, the discover page and the navbar.
3. **Wilaya targeting never filters:** `matchesUser()` reads `$user->wilaya` (`Sponsorship.php:162`), but `users` has no wilaya column.
4. **Two unsynchronised sponsor mechanisms:** the Black toggle (`BlackPepperController.php:881-887`) sets product flags without a row. So: (a) no end date, free, untracked; (b) excluded from the home sponsored row (needs a row) but boosted in every listing; (c) `syncProductFlags()` for another path would reset it; (d) `sponsored_priority` 1–10 is on a different scale from `boost_score` 1–70.
5. **Resumed sponsorships rank last:** `resumeSponsorships()` sets `is_sponsored=true` but leaves `sponsored_priority=0` (`PlanDowngradeService.php:163`).
6. **Pause is modelled as `expired`:** the status enum has no `paused`. Paused rows look expired in every report, and `paused_reason='manual'` is never used.
7. **Conversion attribution is not attribution:** any completed order containing the product during the window counts (`OrderObserver.php:63-79`). There's no click linkage, a double count on delivered→completed, and no reversal on refund.
8. **Admin search filter precedence:** `AdminSponsorshipController@index` l.64-67 uses `whereHas(...)->orWhereHas(...)` without grouping, so `?status=` / `?plan_type=` are bypassed when `search` is set.
9. **Plan resolution inconsistency:** `index()`/`quota()` use the raw plan slug while `sponsor()` uses `tierKey()` (§2.6).
10. **Broken storefront call:** `app/search/page.tsx:323` fetches `/api/sponsorships/public`, which doesn't exist.
11. **Discover double counting:** the discover page re-fetches the same 12 sponsored items for every page and injects them again, and it labels every `publicFeed` item "Sponsored", including the non-sponsored popularity **backfill** (`app/discover/page.tsx:612-626`).
12. **Black free quota ignores duration:** a free slot covers up to 90 days (3 per week, unlimited concurrent with the default `max_sponsored_products=null`).
13. **Undisclosed paid placement:** the navbar dropdown shows sponsored items as "Popular Products" with no label. The dead `RecommendedSection` shows the same pattern. Product listings put sponsored items first, even under explicit `price_asc`/`price_desc` sorts.

### Missing / half-built
- **Payments:** stub only. Any non-empty token is accepted. There's no gateway, no wallet or ad-credit ledger, no invoice or receipt, and no refund on cancel. `payment_reference` is unused, yet `total_revenue` reports sandbox amounts as revenue. The seller UI collects raw PAN and CVV in a first-party form (tokenisation is fake), which is a PCI concern once real money is involved.
- **Scheduling:** `sponsorships:expire` isn't scheduled. Expiry depends on someone hitting a feed or list. `search:rebuild` isn't scheduled either, so embeddings go stale.
- **Tracking:** there is no event log (`sponsorship_events`), so there's no daily series, dedupe, viewer, placement or bot filtering. Impression/click endpoints are public, unthrottled and accept any ID. Impression coverage is inconsistent (viewport on home, mount on category, none on discover, navbar or product page). No spend pacing, budget or CPC/CPM model exists.
- **Relevance:** ads are ranked only by paid priority, then recency. The interest profile, AI similarity and the targeting fit are not used to rank ads (targeting is a binary filter). There are no ad slots on search results, product pages (as ads) or cart.
- **Admin:** no UI, no approval or moderation workflow, pricing isn't configurable (`platform_settings` exists but is unused), and there's no revenue reporting.
- **Seller comms:** no notification or e-mail on activation, expiry, low performance or plan pause. There's no newsletter or marketing consent model at all.
- **Dead code:** `Sponsorship::maybeResetBlackQuota()` (empty body), `ExpireSponsorships` (unscheduled), `products.sponsored_until`, `payment_reference`, `app/components/sections/Hero.tsx`, `app/components/sections/RecommendedSection.tsx`, `app/Mail/SellerMails.php` (duplicate classes), the `BlackPepperController@toggleSponsorship` route (no caller), the stale `ProductScoringService` docblock about `user_activity_logs`, and the doc comment listing seller-scoped impression/click routes.
- **Hard-coded values:** `Sponsorship::BOOST/PRICE/BLACK_FREE_PER_WEEK`, Black overage `1.500` inline (`SponsorshipController.php:170`), surcharge 5 DT per point over 5, duplicated again in `lib/sponsorshipApi.ts:146-160`. Also the Groq model `llama3-8b-8192` (l.53), `SPONSORED_INJECT_SLOTS [1,5]`, the durations list, and the wilaya list in the frontend.

### Performance risks
- **Writes on GET:** `expireOverdue()` runs on every `/api/sponsored-products`, home-feed build, seller list and admin list/stats. It does a per-row `update` plus a `syncProductFlags` (2 queries) for each row, which is an N+1 on a hot public path.
- **No index** on `products.is_sponsored` / `sponsored_priority`, yet nearly every listing orders by them.
- **N+1 queries:**
  - Seller `index` and admin `index` query `ProductImage` per row (`SponsorshipController.php:323-331`, `AdminSponsorshipController.php:71-79`).
  - `publicFeed` calls `PromotionService::getEffectivePrice` per product. It is cached 60 s per product but still runs one query per product on a miss.
- **Heavy in-memory loads:** `publicFeed` loads up to 100 sponsored products with relations, then filters in PHP. `ProductController` scoring path loads 200 products with variants and attributes per request.
- **Synchronous external call:** the Groq request runs inside `POST /sponsor` (up to 12 s).
- **Staleness:** `CandidatePools` caches `is_sponsored` for 10 min, so a cancelled or expired ad can remain eligible in the home pool until refresh (filtered only by the row check).
- **Exposed AI admin endpoint:** the AI service binds `0.0.0.0` with an unauthenticated `/index/rebuild`.

---

## 8. SUMMARY TABLE

| Feature | Exists? | Where | Quality |
|---|---|---|---|
| Sponsorship records (per product campaign) | Yes | `sponsorships` table, `app/Models/Sponsorship.php` | partial (dropped fields, no paused status) |
| Denormalised product sponsor flags | Yes | `products.is_sponsored/sponsored_priority/sponsored_at` | working (not always synced; `sponsored_until` unused) |
| Seller create/cancel/list boost | Yes | `SponsorshipController`, `/seller/promote` | working |
| Pricing model (per-day + surcharge, tier-based) | Yes | `Sponsorship::PRICE/BOOST`, controller l.127-206, duplicated in `lib/sponsorshipApi.ts` | partial (hard-coded, Black quota ignores duration) |
| Real payment capture | No | `processPayment()` stub | broken / sandbox only |
| Ad wallet / credits / invoices | No | — | — |
| Duration & automatic expiry | Yes | `expireOverdue()` (lazy) | partial (command not scheduled) |
| Black Pepper free quota | Yes | `blackFreeRemaining()` | working |
| Black Pepper direct toggle | Yes | `BlackPepperController@toggleSponsorship` | broken (bypasses billing and tracking; no UI caller) |
| Plan gating & max sponsored | Yes | `PlanGate::canSponsor`, admin `PlansManager` | working |
| Pause/resume on downgrade | Yes | `PlanDowngradeService` | partial (status=expired, priority lost on resume) |
| Targeting (gender/category/price/wilaya) | Yes | `Sponsorship::matchesUser` | partial (ignored on public feed; wilaya never works) |
| Relevance/personalized ad ranking | No | ranking = `sponsored_priority, sponsored_at` | — |
| Homepage sponsored row / injection | Yes | `HomeFeedBuilder` l.110-121, 424-452, 644-665 | working |
| Category / discover / navbar sponsored rows | Yes | `SponsoredProductsSection`, `discover/page.tsx`, `Navbar.tsx` | partial (labelling and tracking gaps; discover mislabels backfill) |
| Search results sponsored | No (attempted) | `app/search/page.tsx:323` calls a non-existent route | broken |
| Product-page / cart ad slots | No (only badges on organic recs) | `ProductRecommendations.tsx`, `CartDrawer.tsx` | — |
| Sponsored boost in organic listings | Yes | `ProductController@index`, `ProductRecommendationController`, `ProductScoringService` | working (overrides explicit sort) |
| "Sponsored" disclosure | Partial | `SponsoredBadge`, `FeedProductCard` | partial (navbar undisclosed) |
| Impression tracking | Yes | public `POST /sponsorships/{id}/impression` | partial (unauthenticated, no dedupe, inconsistent triggers) |
| Click tracking | Yes | public `POST /sponsorships/{id}/click` + `/api/track` | partial |
| Conversion attribution | Yes | `OrderObserver` | broken (no click linkage, double counts) |
| Seller ad analytics | Yes | `/seller/promote/analytics` | partial (totals only) |
| AI ad copy / tags | Yes | Groq in `generateAiContent()` | working (synchronous) |
| Auto-promote suggestions | Yes | `AutoPromotionService`, `SmartPromoteCard` | working |
| Admin sponsorship API | Yes | `AdminSponsorshipController` | partial (search filter bug) |
| Admin sponsorship UI / approval / pricing / revenue | No | — | — |
| VIP manual promotion requests | Yes | `vip_requests`, admin `vip-requests` page | working (manual) |
| Discount promotions (flash sale / discount) | Yes | `promotions`, `PromotionService`, `promotions:sync` | working (separate concern) |
| Behaviour tracking (views, clicks, cart, fav, follow, search, purchase) | Yes | `user_interactions`, `InteractionTracker`, `/api/track` | working |
| Interest profiles | Yes | `user_interest_profiles`, `InterestProfileService` | working |
| Product/user similarity (AI) | Yes | FastAPI `/similar`, `SimilarProductsFinder` | working (index rebuild manual) |
| Entry popup / banner system | No (only `ReviewPromptPopup`) | `app/layout.tsx:71-72` | — |
| E-mail infra | Yes | Brevo SMTP, 6 Mailables, blade templates, sync queue | working (no queue worker) |
| Newsletter / marketing consent | No | — | — |
| Sponsorship notifications/e-mails to sellers | No (admin DB notification only, Black toggle) | `SponsoredProductActivatedNotification` | — |
