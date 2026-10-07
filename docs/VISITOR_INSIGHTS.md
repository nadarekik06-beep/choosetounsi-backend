# Visitor Insights (Analyse des visiteurs)

Seller dashboard → Black Pepper → **Analyse des visiteurs** (`/seller/black/visitor-insights`).
Where and why buyers drop off: impressions → clicks → views → cart → checkout → order.

Page boundaries (no overlap):

| Page | Question | Never shows |
|---|---|---|
| Statistiques (`/seller/analytics`) | What did I sell? | diagnosis |
| Qualité des fiches | Is my listing well built? (content only) | traffic |
| **Analyse des visiteurs** | Where and why do buyers drop off? | a revenue / units product table, its own health score |
| Radar de croissance | This week's to-do across all signals | the full funnel (its `leaking_product` card links here) |

The listing-quality score (`ProductQualityService`) is reused as one possible cause; the
Growth Radar `leaking_product` card uses the same diagnosis rules (`FunnelDiagnosis`).

## Tracking

| Step | Where it is recorded | Table |
|---|---|---|
| impression | storefront `ProductCard` (≥50 % visible 1 s), batched, once per session + product + listing context | `product_funnel_events` |
| click | `ProductCard` → `POST /api/track` (section) | `user_interactions` |
| product_view | product page → `/api/track` (section of the click that led there, or `external` / `direct`) | `user_interactions` |
| add_to_cart / add_to_wishlist | server side (`CartController`, `FavoriteController`), source = the actor's last view | `user_interactions` |
| checkout_started | checkout page → `/api/track` with a checkout-attempt id (once per session per order) | `product_funnel_events` |
| order | `seller_orders` in a sale status, attributed to the buyer's last view (7 days) | — |

Sources: `search, category, home, sponsored, storefront, external, direct`
(`config/funnel.php` maps storefront sections by prefix). Device from the User-Agent.

Excluded: bots (User-Agent; history: > 150 product views/day by one actor), platform staff,
and the seller on their own products — logged in, or on a browser they once logged in with.
Live rows are flagged at write time (`funnel_excluded`); history by the backfill.

## Pipeline

| What | Command |
|---|---|
| Nightly 01:10 (Africa/Tunis) | `php artisan funnel:aggregate` — rebuild the last 3 days + today, benchmarks, prune raw events (> 120 days) |
| Hourly :20 | `php artisan funnel:aggregate --today` |
| History | `php artisan funnel:aggregate --backfill=90` — flag excluded history, give old events a source, rebuild 90 days |
| One day | `php artisan funnel:aggregate --date=2026-10-01` |

Aggregates (the page never reads raw events):
- `product_daily_stats` — per product and local day: impressions, clicks, views (unique per
  session per day), unique_visitors, add_to_cart, wishlist, checkout_started, orders, units,
  revenue, views per source.
- `product_daily_traffic` — same, per source × device (source conversion, device split).
- `funnel_benchmarks` — medians per subcategory / category / platform for 7/30/90 days: CTR,
  view→cart, cart→order, conversion, views and impressions per product, price, delivery fee.
  Published only above the Growth Radar privacy floor (≥ 5 shops, ≥ 30 views).
  Lookup fallback: **subcategory → category → platform → the seller's own previous period**;
  every number says which one was used (`scope`), and the page shows it.

Impressions and checkout starts can't be rebuilt for the past: they count from launch, and
rates that need them are only judged over periods fully covered (`tracking` in the payload).

## Diagnosis (`FunnelDiagnosis`, config `funnel.*`)

Products with < 30 views get "not enough data" (never "all good"), except a visibility
problem, which is judged on the product's age (≥ 7 days listed).

| Stage | Problem | Rule |
|---|---|---|
| click | `low_visibility` | views < 35 % of the benchmark's views per product (pro-rated to days listed) |
| click | `low_ctr` | ≥ 150 impressions, CTR < 60 % of the benchmark |
| product_page | `price_high` → `listing_quality` → `no_reviews` → `stock_variants` → `low_add_to_cart` | view→cart < 60 % of the benchmark; first cause that applies |
| cart | `stock_variants` → `shipping_cost` → `cart_abandon` | ≥ 5 carts, cart→order < 60 % of the benchmark |
| checkout | `stock_variants` → `shipping_cost` → `checkout_abandon` | as cart, when checkout→order < 50 % and loses more than cart→checkout |

Severity: high below 30 % of the benchmark, else medium.
Lost revenue = views × max(0, benchmark conversion − product conversion) × price; for click-stage
problems, the missing visits × conversion × price. Never negative. Fixes are ranked by it.

## Actions and results

Buttons deep-link to the existing flows (edit product with focus, discount — never a direct
price edit —, AI description generator, boost, restock, Qualité des fiches). What is applied is
stored in `growth_actions` with `origin = visitor_insights`:
- discount / flash sale / coupon / boost: the endpoints accept `insight_product`,
  `insight_problem`, `insight_stage` (like `growth_card_id`);
- listing edit / restock: the dashboard calls `POST /api/seller/black/visitor-insights/actions`
  after the save.

Before / after: the 14 days before vs the days after (from 7 days, both sides ≥ 30 views).
Growth Radar's own measurement ignores these rows.

## API (Black Pepper — `seller.feature:black_hub`)

- `GET /api/seller/black/visitor-insights?period=7|30|90` (cached 15 min per seller and period)
- `POST /api/seller/black/visitor-insights/actions` `{product_id, kind: edit|restock, problem_code?, stage?}`

## Demo

```
php artisan funnel:demo            # funnel-demo-black (every stage), funnel-demo-new (low data), funnel-demo-empty, 5 peers
php artisan funnel:demo --fresh
php artisan funnel:demo --purge
```
Password: `FunnelDemoSeeder::PASSWORD`. Only `funnel-demo-%@choosetounsi.test` and the
`demo-funnel-%` category are touched.
