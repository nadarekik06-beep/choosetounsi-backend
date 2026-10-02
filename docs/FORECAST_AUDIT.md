# Sales Forecast — audit of the old implementation (2026-10-02)

Baseline tagged `pre-forecast-rebuild` in backend, frontend and admin-panel.

## What it read
- `order_items` ⨝ `orders` filtered on **`orders.status` IN (pending, processing, completed, delivered)** — not `seller_orders`.
  Unconfirmed COD orders (`pending`) counted as sales; `confirmed` / `out_for_delivery` ignored. Cancelled/refunded
  were excluded only by omission. Approved return/refund complaints were never netted out.
- Revenue = `oi.total` (gross, before discounts); `net_total` / `discount_amount` ignored.
- Stock = `products.stock` only — variant stock never read. No variant-level anything.
- `order_items.promotion_id` (flash sale/discount marker) never used — promo days inflate the baseline.
- No views / add-to-cart / favourites signals in the forecast.

## Why "19 units / 1 691 DT, 0 orders, 10/100"
Product 38 and 109 (both 89 DT, 0 sales) return the exact same numbers:
1. Own history empty → `base = 0.1 × 0 + 0.9 × peerAvg`. `peerAvg` = mean of (units ÷ *months with a sale*) over 8
   products of the same category at any price, pending orders included → biased up (~3.2).
2. Each of 6 months: `max(1, round(base × hardcoded seasonal index × event boost))` → 3,3,3,3,4,3 = **19**; × 89 DT = **1 691 DT**.
   The `max(1, …)` floor means a forecast can never be 0.
3. **10/100** is the demand-score gauge: slope 0 ⇒ "trend score" 10, all other components 0. The separate confidence
   field is `25 + orders/20×75` → 25 even with zero data.

## Hardcoded / fake
- `TUNISIA_COMMERCE_INDEX` (e.g. March ×1.35 "Ramadan") and 7 `SEASON_MONTHLY_PROFILES` blended 60/40 and multiplied
  into every month. Ramadan/Aïd profiles are **fixed to Gregorian Feb–Jun** (Ramadan moves ~11 days/year).
- Event boost = `product_event_signals.boost_score` × a guessed `alignmentMap` factor.
- `/forecast/events`: `categoryBaselineMatrix` of guessed multipliers (Ramadan × clothing = 1.55 …) shown to sellers as
  "×1.55", "+55 %", "predicted units" and a fake 30–95 % confidence. (`product_event_signals` is currently empty, so the
  card shows nothing — the seeder `TunisianEventSignalsSeeder` holds the guessed boost scores.)
- `computeSeasonWeightedAverage` divides then multiplies by the same number (no-op); `max(weighted, avg)` biases up.
- History average skips zero-sale months; projections start next month (current month skipped).
- Stock recommendation = next 3 months × 1.30, fixed.
- English-only server strings (blend note, AI fallback, event explanations, stock actions); header badge "🔴 Red Pepper" hardcoded.

## The 60-second auto-refresh
Re-fetches only `/forecast/regional` + `/forecast/similar` (≈8 SQL incl. 2–3 `SHOW COLUMNS`) every 60 s per open tab,
and again on every window focus / tab switch. Forecast itself is not recomputed, so the refresh changes nothing useful.
Each product selection or "Actualiser": forecast recomputed (cache bypassed — `refresh=true` default, ~8 SQL + 2 writes),
regional, similar, events (2 aggregates per event + 1 Groq call on the **decommissioned `llama3-8b-8192` model** — always
fails after up to 12 s, falls back to English text) and `/forecast/explain` (1 Groq call, never cached, prompt built from
data the client posts back).

## Bugs
- **Data leak**: `/forecast/regional` never checks product ownership — any Red seller can read another seller's
  per-governorate sales by product id.
- `clearForecastCache` deletes `forecast_cache` rows of a product for all sellers; `cacheAge` only reads the 6-month key.
- `aiExplain` trusts client-supplied numbers.

## Dead code
- `AIToolsPanel.tsx`: `SalesPredictorTool` + `SeasonSelector`, `SeasonBreakdownPanel`, `AlgorithmPanel`, `MiniBarChart`,
  `ProductDNAStrip` (~600 lines, never rendered).
- `SellerAIController::salesPredictor` (~580 lines, own hardcoded multipliers) + route `/seller/ai/sales-predictor`.
- `forecast_cache` (written, only read by unused `cache-age`), `regional_demand_cache` (0 rows, unused).
