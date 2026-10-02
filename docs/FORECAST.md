# Sales forecast — how it works

Seller dashboard → Outils IA → **Ventes**. Every number is computed by `app/Services/Forecast/*` from our own data.
Groq only rewrites those numbers into 2–3 sentences; if it fails or invents a number, a template text is used.
Audit of the previous implementation: [FORECAST_AUDIT.md](FORECAST_AUDIT.md). Settings: `config/forecast.php`.

## 1. Data (`SalesSeries`)
- Sales = `seller_orders` in **confirmed / out_for_delivery / delivered / completed**. Pending, cancelled, refunded never count.
- Items of **approved return/refund complaints** are removed. Revenue = `order_items.net_total` (after discounts).
- Daily series per product **and per variant** in `forecast_daily_sales` (local Tunis days), rebuilt for the seller at each run.
- `promo_units` = units sold through one of our promotions (`order_items.promotion_id`); `promo_day` = a flash sale /
  discount window or a sponsored boost covered that day.
- Leading signals from `user_interactions`: views, add-to-cart, favourites (→ cart rate, conversion).

## 2. Tiers (cold start)
| Tier | When | What the seller sees |
|---|---|---|
| `insufficient` | 0 orders and no usable category data | No numbers — what to do instead (visibility, price, description) |
| `category` | 0 orders, category data OK | "Estimation basée sur la catégorie", range only |
| `blend` | 1–9 orders (or < 56 days) + category data | Range; seller weight shown (e.g. 70 % vos données) |
| `limited` | few orders, no category data | Range from own data with a weak prior |
| `own` | ≥ 10 orders over ≥ 56 days | Model chosen by backtest |

**Category prior** (`CategoryPrior`): products of *other* shops, same category, price ×0.5–×2 (fallback: whole category),
live, listed ≥ 14 days, last 90 days, products without sales included (rate 0). Floors: ≥ 5 products, ≥ 3 shops,
≥ 10 orders — below that there is no estimate. Only aggregated rates are used.

**Blend** = Gamma–Poisson shrinkage: a Gamma prior fitted to the peers' daily rates (strength capped at 60
"pseudo-days"), updated with the seller's own non-promo days and units. The seller's weight = own days ÷ (prior days + own days),
so it grows as their history grows. Ranges are the exact negative-binomial predictive quantiles.

## 3. Models (`Models`, `ProductForecaster`)
Weekly series of normal (non-promo) sales:
- promo days removed and the week rescaled (a promotion/boost covering > 50 % of the history is treated as normal);
- intermittent demand (avg. interval > 1.32 weeks, Syntetos–Boylan): **Croston / SBA** (+ mean as benchmark);
- otherwise: moving mean, SES, damped Holt, **seasonal naive** (≥ 60 weeks), **Holt-Winters** (≥ 2 years).
- **Selection by backtest**: hold out the last 4 + previous 4 weeks (or four 13-week windows over the last year
  when ≥ 73 weeks, so seasonality is tested); lowest MAE wins, a more complex model must be > 2 % better.
- **Ranges (80 %)**: P10–P90 of a negative binomial with variance `φ·μ + (c·μ)²` — φ = observed over-dispersion,
  c = 1/√orders (level uncertainty), growing with the horizon. 4 weekly buckets + 6 calendar months.
- **Variants**: product forecast split by the variants' 180-day sales mix (+1 smoothing) — per-variant series are too sparse.
- **Confidence** (0–100): `own` = 0.4 × data (orders on a log scale, history up to 6 months) + 0.6 × accuracy
  (100 − backtest error % of a normal week); `blend/limited` ≤ 45; `category` ≤ 20; `insufficient` = 0.
  Shown with one line: "Basé sur 3 commandes sur 60 jours — fiabilité faible".

## 4. Tunisian calendar (`calendar_events`)
- Data only: names FR/EN/AR, dates, optional categories, active. **No uplift % field.**
- Islamic holidays: `php artisan forecast:hijri [year]` (tabular Hijri calendar — may be 1–2 days off the official
  announcement; source = `hijri` until an admin edits them). Never tied to a Gregorian month.
- Used for: chart markers, "Actions recommandées" 0–6 weeks before (prepare stock, schedule a promotion), alerts ~5 weeks before.
- **Measured effects** (`EventEffectMeasurer`, nightly): once an event + 4 weeks have passed, per category: daily units during
  the event vs the 4 weeks before + 4 weeks after (other events' days excluded), with order counts.
- **Threshold: ≥ 30 orders during the event AND ≥ 30 in the baseline** before an effect enters the forecast. With 30
  orders the sampling error on a rate is about ±36 % (95 %), so a measured +30–40 % starts to be distinguishable from noise;
  lower counts would let a couple of orders swing the forecast. Below the threshold the effect is still shown as information,
  reminders only. When applied, the multiplier is clamped to ×0.3–×3.
- Promotion uplift is measured the same way and applied only to *scheduled* promotions with ≥ 5 promo orders.

## 5. Serving & cost
- Nightly 01:30 (Tunis): `forecast:compute` → measures events, compacts old snapshots, one queued `ComputeSellerForecast`
  per eligible seller (plan with `analytics`).
- Recompute automatically: sub-order entering/leaving a counted status, approved return, restock (unique per seller, 2 min delay).
- "Actualiser": synchronous, once per 10 min per seller. No polling.
- `forecast_snapshots`: one row per product / variant / shop per day; full payload kept 7 days, then only the summary
  (`h28_*`, `actual_28`) for the accuracy track record (coverage of the 80 % range, WAPE).
- Groq: at most one call per product × language × day (cache), never on page load when cached; template fallback.

## 6. Alerts
In-app + e-mail (`ForecastAlertNotification`, queue `notifications`): stock-out within the seller's window (only for
`own` / `blend` / ≥ 3 orders), event ~4–6 weeks ahead (once per event), sales below the forecast range (once per 14 days).
Weekly digest (opt-in) Monday 08:00. Seller settings: alerts on/off, e-mail on/off, digest, alert window, lead time, safety days.

## 7. Local testing (Windows / XAMPP)
```
php artisan migrate
php artisan db:seed --class=ForecastDemoSeeder      # demo shop: forecast-demo@choosetounsi.test / demo-forecast-2026
php artisan forecast:compute --sync --no-alerts     # one-off nightly run in the console
php artisan queue:work --queue=notifications,default   # alerts / queued recomputes
php artisan schedule:work                           # runs the scheduler every minute (dev)
php artisan forecast:demo-purge                     # remove the demo data
php vendor/bin/phpunit tests/Unit/Forecast tests/Feature/Seller/SalesForecastTest.php
```
