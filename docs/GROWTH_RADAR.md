# Growth Radar

Seller dashboard → **Growth Radar**. Replaces the old Black Pepper "AI Intelligence" and
"Intelligent Promotion" sections. A weekly score plus 3–7 action cards; each card opens an
existing flow (discount / flash sale, coupon, boost, product form, bundle recommender)
pre-filled, and what the seller applies is measured afterwards.

All numbers are rules and simple statistics on our own data (`app/Services/GrowthRadar`).
Groq (free tier) only rephrases a card headline; a rewrite that changes or adds a number is
dropped (`Headlines`, same check as the forecast narrator).

## Pipeline

| Step | Where |
|---|---|
| Nightly 02:30 (after `forecast:compute`) | `php artisan growth:compute [--seller=ID] [--no-notify]` |
| Per seller | `GrowthRadar::compute()` → `SellerContext` (rebuilds the forecast daily series) → detectors → `persist()` → `Scorer` → headlines → notification |
| Read | `GET /api/seller/growth-radar` (precomputed; a seller never computed is computed on first visit) |
| Act | the existing endpoints accept `growth_card_id` (promotions, coupons, ad campaigns) → `growth_actions` |
| Measure | `growth:measure` (phase 3) |

## Cards

| Type | Rule (config/growth.php) | Action |
|---|---|---|
| `price_position` | above p75×1.1 of the category range and converting below the benchmark → discount test to ~median; below p25×0.9 and selling ≥3/month → raise the price | discount / product form |
| `leaking_product` | ≥40 views, conversion < half the benchmark; fix = photos, description, price vs median, else price test | product form / generator / discount |
| `dead_stock` | ≥5 in stock, no sale for 45 days; few views → boost, else clearance | discount or boost (+ bundle) |
| `warm_audience` | ≥3 buyers favourited / carted and did not buy | coupon private to them |
| `seasonal` | next calendar moment (admin calendar) in 5–49 days for the seller's categories | discount / flash sale from the best start date |
| `hidden_demand` | missed searches (≤2 results) in the seller's categories, ≥30 searches | list a product |
| `promo_timing` | platform's busiest weekday/hour beats average by ≥1.25× | flash sale at that slot |

Ranking: low-confidence cards always below medium/high, then by the middle of the impact range.
Impact is always a range in DT, rounded so it never looks precise; `null` when it cannot be
estimated (then the card says so).

## Privacy

- Cross-seller numbers (category price range, conversion benchmarks, traffic pattern) need
  **≥5 shops**, and rates also **≥30 events** (`Benchmarks`). Search volumes need ≥30 searches.
  Never a shop name or one shop's numbers.
- A seller's own data has no floor, only a confidence label.
- Targeted coupons: the seller sees a count, never who. Minimum audience 3; a buyer gets at
  most one targeted coupon per 7 days across all shops (`Audience`). Buyers get a bell
  notification; e-mail only with marketing consent (`users.marketing_emails_opt_in`).

## Tiers

`config('growth.full_feature')` (= `black_hub`) gets the feed. Other plans get the score and
one card reduced to type / confidence / impact range by the server (`Presenter::locked`).

## Demo

```
php artisan growth:demo            # Black Pepper shop growth-demo@choosetounsi.test + peers, Red, Green
php artisan growth:demo --fresh    # purge + reseed
php artisan growth:demo --purge
```
Password: `GrowthDemoSeeder::PASSWORD`. Only `growth-%@choosetounsi.test`, the `demo-growth-%`
category and the `growth_demo_event` calendar rows are touched.
