# Discount pricing audit — Choose'Tounsi

Date: 2026-11-06 · Scope: product promotions (seller + admin), flash sales, packs, seller coupons.
Phase 1 only: read-only audit, no code changed.

## Summary

The core is in good shape. One backend service prices everything
(`app/Services/Orders/OrderPricing.php`). It uses integer millimes and is called
by the quote, the cart checkout and buy-now. The order is re-priced on the server
when it is placed, and client prices are never trusted. The final price is frozen
on `order_items` / `seller_orders`, and the Intigo COD amount is that frozen
figure. Finance screens read the frozen figures.

The main problems are in **packs** (partial returns refund the wrong amount, and
the seller-side price is not validated) and in **coupon life-cycle** (a coupon
use is not given back on cancel, the history is deleted with the coupon, and the
per-customer limit has a race).

| Severity | Count |
|---|---|
| CRITICAL | 1 |
| HIGH | 2 |
| MEDIUM | 8 |
| LOW | 8 |

---

## 1. Where the final price is calculated

| Place | File:line | Role | Can disagree with checkout? |
|---|---|---|---|
| Promotion price (single source) | `app/Services/PromotionService.php:101-131, 204-260` | `priceLine()` / `pricing()`: winning promotion, 30-day reference price, final price | — (source) |
| Order money (single source) | `app/Services/Orders/OrderPricing.php:62-404` | lines, packs, coupons, commission, delivery, COD, payout | — (source) |
| SQL copy for list sort/filter | `app/Services/PromotionService.php:142-168` | `finalPriceSql()`, the same rule in MySQL DECIMAL | Lists only; float vs DECIMAL rounding can differ by 1 millime (L-1). Covered by `tests/Feature/Promotions` |
| Cart display | `app/Http/Controllers/Api/Client/CartController.php:440-484` | calls `priceLine()` | No (same service). Pack rows show `pack_price_snapshot`, and checkout returns 409 if the pack price changed |
| Coupon preview | `app/Http/Controllers/Api/Client/CouponController.php:56-88` | `priceLine()` + `validateForSeller()` | No (same rules); preview only |
| Checkout quote / order | `app/Http/Controllers/Api/Client/CheckoutController.php:80-288` | `OrderPricing::forCart/forBuyNow`; `expected_total` only detects a change (409) | No |
| Storefront checkout | `choosetounsi-frontend/app/checkout/page.tsx:857, 931, 992` | shows the quote and sends `expected_total = quote.total` | No |
| **Seller promotion form preview** | `choosetounsi-frontend/app/seller/promotions/PromotionModal.tsx:64-75, 160, 196, 230, 815` | computes `price × (1 − %)` in JS on the **current** price | **Yes** (M-6): the server discounts the 30-day lowest price |
| **Seller coupon form preview** | `choosetounsi-frontend/app/seller/promotions/CouponModal.tsx:20-23, 334` | JS computation on the base price | Yes, but display only |
| Deals page coupon % | `choosetounsi-frontend/app/deals/_components/model.ts:92-93` | display % | Display only |
| Admin promotion preview | `AdminPromotionController::preview` → `PromotionService::preview()` | server-side | No |
| Order / invoice / finance screens | `Order::moneySummary()` (`app/Models/Order.php:196-237`), `SellerInvoiceController`, `FinanceController`, `EarningsController` | read the stored `seller_orders` / `order_items` | No (no client-side recomputation found in admin-panel or seller dashboard) |

There is no duplicate checkout-price calculation in the backend.

## 2. Mechanisms

### Promotions (seller + admin), including flash sales
- **Creation**: `SellerPromotionController::store` (`:55-140`) and `AdminPromotionController::store` (`:158-235`). Both write the same `promotions` table. Pricing is identical (`PromotionService`).
- **Validation**: value > 0, end > start, and the final price must be > 0 on every variant (`zeroPriceProducts`). Sellers: flash 1-72 h and ≤ 90 %, discount 1-90 days and ≤ 70 %. Admin: any duration, ≤ 99 % (owner decision 2026-11-05, `PromotionService.php:536-565`).
- **One promotion per product at a time**: `PromotionService::conflicts()` (`:677-692`). Sellers get an error. Admins get a 409 and can choose to replace.
- **Dates**: stored in UTC (`config/app.php` timezone = `UTC`). Both forms convert the browser's local time with `new Date(v).toISOString()` (`PromotionModal.tsx:59`, `admin-panel/app/(dashboard)/promotions/page.tsx:47`). This is correct when the device is set to Tunis time (L-3). Activity is decided by dates, not by the cron (`Promotion::scopeActive`, `:61-68`).
- **Flash stock**: `flash_stock` / `flash_stock_used` per promotion (shared by all its products). Units are reserved by an atomic conditional UPDATE (`PromotionService::reserveFlashStock`, `:294-301`) inside the order transaction. A sold-out flash sale refuses the order (`FlashSaleSoldOut`, `CheckoutController.php:378`). It never charges full price silently. Units are released on cancel, returned-to-seller, failed card payment and full return.
- **Variants**: the discount applies to the variant's own price (`price_override`) and its own 30-day history.

### Packs
- Created by sellers only (`SellerPackController::store`, `:57-135`). Admins only approve, toggle or delete. Packs have no dates and no usage limit; stock = component stock.
- Priced at the pack's **current** `pack_price` × quantity (`OrderPricing.php:200`). Promotions and coupons never apply to packs. A pack spanning several sellers is split by value (`splitByWeight`, remainder on the last seller).
- On the order, the **first component line of each seller carries all the money**. The other component lines are written with 0 (`CheckoutController.php:415-454`).

### Seller coupons
- Created in `SellerCouponController::store` (`:45-145`). Fields: code (unique), % (≤ 100) or fixed amount, min order, total limit, per-customer limit, expiry, product list. No start date.
- Validated in `CouponService::validateForSeller` (`:33-105`): active, not expired, audience, total limit, per-customer limit (by user, or by phone for guests), eligible products, **refused if any eligible item has an active promotion**, and min order on the eligible subtotal.
- One coupon per seller per order (`OrderPricing::resolveCodes`, `:263-277`). Only product lines get a coupon, never packs. The discount is split across eligible lines in millimes, with the remainder on the last line (`allocateDiscountM`).
- Redeemed inside the order transaction, under a row lock on the coupon (`CouponService::redeem`, `:172-192`).

## 3. Stacking — current behaviour and proposed rule

What happens today when a product has several discounts:

| Situation | Today |
|---|---|
| Flash sale + promotion on the same product | Cannot be created: the overlap is blocked for sellers, and admins must replace. If it exists anyway (a paused promotion resumed, legacy data), the flash sale wins, then higher `priority`, then the newest. **Never stacked.** |
| Product in a pack + promotion on that product | The pack row uses the pack price only. The promotion is ignored inside the pack and no flash units are used. **Never stacked.** |
| Coupon + promotion on the same item | The **whole coupon is refused** if any eligible item in the cart is promoted. **Never stacked.** |
| Coupon + pack | Coupons never apply to pack rows. |
| Two coupons from the same seller | Refused. |
| Coupons from different sellers | Allowed, one per seller parcel. |
| Admin promotion vs seller promotion | Same pricing path and the same priority values (flash 10, discount 5). Differences: admin max is 99 % with any duration, and admins can replace a seller promotion. |

**Proposed single rule** (one line per item, never stacked):

1. **Pack row** → pack price. Nothing else applies.
2. **Product row** → at most one promotion: **flash sale > promotion** (admin = seller). Ties: higher priority, then the newest.
3. **Coupon** → only on the coupon's products from that seller that have **no** promotion, in the same order. The proposed change is to **skip** promoted items rather than refuse the whole coupon (M-5, decision needed).

## 4-10. Findings by severity

### CRITICAL — wrong amount refunded / paid

**C-1. Partial return of a pack refunds the wrong amount, and commission and seller balance are reversed wrongly**
- Files: `app/Http/Controllers/Api/Client/CheckoutController.php:415-454` (money on the first component line only), `app/Http/Controllers/Api/Client/ComplaintController.php:301-319` (every pack line is returnable on its own), `app/Services/Returns/ReturnService.php:1054-1072` (`share()` is proportional to the line's own money), `:856-946` (`applyFinance`).
- Cause: a pack is stored as N lines, with all the money on line 1 and 0 on the others, and there is no `pack_id` on `order_items`. A return is computed per line.
- Failure: Pack (A + B) = 50 DT.
  - The client returns **A only** → refund **50 DT** while keeping B. The full commission and the full seller share are reversed.
  - The client returns **B only** → refund **0 DT**.
  - The client sees `paid_unit_price` = 50 for A and 0 for B on the return form.
- Fix (Phase 2):
  - Add nullable `order_items.pack_id` and `pack_group` (one group per pack row and seller) in a backward-compatible migration.
  - Spread the seller's pack share over the component lines by value (same `splitByWeight`, remainder on the last line), so each line holds its own `total / net_total / commission / seller_amount`.
  - For **old** orders, do not change them. Instead, either block a partial return of a legacy pack (whole pack only), or compute the refund pro-rata at return time from the components' weights.
  - Add a report command that lists legacy pack orders with returns already applied.

### HIGH

**H-1. Pack price is not validated on update, and can be 0 on create**
- File: `app/Http/Controllers/Api/Seller/SellerPackController.php:62` (`min:0`), `:150` (`update` has **no validation**: any value is stored, including a negative one).
- Failure: a negative `pack_price` gives a negative line in the seller's parcel. That lowers the COD sent to Intigo, gives a **negative commission** (the platform pays out), and lowers platform revenue. A 0 DT pack gives the products away.
- Fix:
  - Require `pack_price` numeric with `gt:0` on create and update, at most 3 decimals.
  - Optionally require it ≤ `original_price` (a pack must not cost more than its items).
  - In `OrderPricing::packLines`, refuse any pack with price ≤ 0 as a safety net.

**H-2. A coupon use is not given back when a COD order or parcel is cancelled**
- Files: `CouponService::releaseForOrder` (`:195-203`) is only called by `CancelAbandonedCardOrders.php:68`. `ParcelStatus.php:346-349` and `SellerOrderObserver.php:37-44` release stock and flash units, but **not the coupon**.
- Failure:
  - A cancelled order keeps `usage_count` and the redemption row. The coupon hits its total limit early, and the customer loses a per-customer use.
  - The seller's "discount given" stats still count it (`SellerCouponController::stats`).
  - Re-opening a cancelled parcel does not check the coupon again.
- Fix: add `releaseForSellerOrders([...])` (scoped to `seller_order_id`, since a coupon belongs to one parcel). Call it next to the flash release on cancel. On re-open, reclaim under lock or refuse the re-open, the same way flash units work.

### MEDIUM

**M-1. Deleting a coupon deletes its redemption history**
- Files: `SellerCouponController.php:205-210` (hard delete), and the migration `2026_09_22_000005_create_coupon_redemptions_table.php:13` (`onDelete('cascade')`).
- Effect: the per-customer usage history is gone. A new coupon can reuse the same code and starts from zero. The coupon history disappears from finance and stats. Order amounts are safe: `seller_orders.coupon_code` and `discount_amount` are snapshots, and `coupon_id` is set to null.
- Fix: deactivate instead of deleting once the coupon has redemptions (the same pattern as promotions: "ended instead of deleted"). Optionally add `SoftDeletes`.

**M-2. The per-customer coupon limit is not checked under lock**
- Files: `CouponService::redeem` (`:172-192`) locks and re-checks only the **total** limit. The per-customer check runs in `forCart()` **before** the transaction (`CheckoutController.php:148`). The buyer lock (`CheckoutGuard::begin`) serialises orders, but each request was priced before it got the lock.
- Failure: two orders from the same customer with different carts, sent at the same time, can both use a "1 per customer" coupon. A guest using two cart tokens with the same phone can do the same.
- Fix: inside `redeem()`, under the coupon row lock, recount the uses by user / phone, and refuse if the limit is reached.

**M-3. A coupon that runs out during checkout returns a generic 500**
- File: `CheckoutController.php:189-196`. The `RuntimeException('Coupon usage limit was reached.')` from `redeem()` falls into the generic `\Throwable` handler and returns `order_failed` (500).
- Effect: the amount is correct (rolled back), but the customer gets no explanation.
- Fix: add a `CouponExhausted` exception and return a 422 with a clear message, plus a fresh quote, like `flash_sold_out`.

**M-4. The order snapshot lacks the discount type and terms, and packs have no discount snapshot**
- File: `CheckoutController.php:380-404`. Stored today: `promotion_id`, `promotion_source`, `original_unit_price`, `promo_discount_amount`, `unit_price`, the coupon share (`discount_amount`) and `net_total`. Not stored: promo type (flash or discount), `discount_type` / `discount_value`. Pack lines store no pack id, pack original value or pack savings.
- Effect: amounts are frozen correctly. But the "what discount was this" label depends on the live promotion row, which an admin can edit while it runs (discount value, end date).
- Fix: add nullable `promo_type`, `promo_discount_type`, `promo_discount_value`, `pack_id` and `pack_original_value` columns, filled at checkout. Old rows stay null.

**M-5. A coupon is refused as a whole if any eligible item is promoted (decision needed)**
- File: `CouponService.php:79-84`.
- Effect: the customer is never overcharged. But a coupon on products X and Y fails completely when only X is on promotion. The requested rule ("only on that seller's selected products, never stacked on the same item") suggests skipping X and discounting Y.
- Also, `getActivePromotionForProduct()` counts a promotion that pricing ignores (one that would bring the price to ≤ 0), so the coupon is refused for an item sold at full price.
- Fix: decide the rule. Proposed: drop promoted items from the eligible list, and use the line's own `pricing['promotion']` (the one actually charged) instead of a second query.

**M-6. The seller promotion preview differs from what buyers pay**
- File: `choosetounsi-frontend/app/seller/promotions/PromotionModal.tsx:64-75`. The JS preview applies the discount to the current price. The server applies it to the lowest price of the last 30 days (`PromotionService::referencePrice`).
- Effect: if the seller lowered the price in the last 30 days, the seller sees a higher final price than the cart charges.
- Fix: use a seller version of the admin's server preview (`PromotionService::preview()`), and show only API values.

**M-7. A promotion can move the commission tier, but a coupon cannot (decision needed)**
- Files: `OrderPricing.php:327` and `CommissionService.php:169-188`. The rate is resolved on the **final** unit price after the promotion, but on the price **before** the coupon.
- Example: a 120 DT product at 12 % sold at 96 DT under a promotion pays 15 %.
- Commission is always charged on the final discounted amount, which is correct. Only the tier choice is inconsistent between the two mechanisms.
- Fix: choose one rule (recommended: the tier from the final unit price for both, or from the pre-discount price for both) and document it.

**M-8. Who pays for an admin promotion (current behaviour, for confirmation)**
- `funded_by` is always `'seller'` (`AdminPromotionController.php:201`, migration `2026_11_05_000001`). The seller is paid on the discounted price. The platform also gives up its commission on the discount amount (commission = rate × discounted price).
- Seller and admin promotions are treated the same in finance. If the platform should pay for its own campaigns, Phase 2 would need a `platform_funded_amount` per line, added back to the seller payout and taken off platform profit. **Owner decision required. No change planned unless asked.**

### LOW

- **L-1. Floats in promotion price math.** `PromotionService::pricing()/applyDiscount()` (`:220, 254-260`) use PHP floats, then `round(…, 3)`. The result goes to millimes in `OrderPricing`, and checkout and screens agree because they use the same function. The SQL copy uses DECIMAL, so a list sort or filter can differ by 1 millime in rare half-way cases. Fix: compute the promotion price in integer millimes (`Millimes::percent`).
- **L-2. Floats in return shares.** `ReturnService::share()` (`:1054-1072`) rounds each figure with `round(float × ratio, 3)` on its own. `commission + seller_amount` can drift 1 millime from `net` on a partial return. Fix: compute in millimes, with `seller_amount = net − commission`.
- **L-3. Timezone depends on the device.** Promotion and coupon dates use the browser's timezone. A seller or admin whose device is not on Tunis time gets shifted windows. The comment at `PromotionService.php:19-20` ("UTC, the same instant as Africa/Tunis wall-clock time") is misleading: Tunis is UTC+1. Fix: send naive local times plus an explicit `Africa/Tunis` parse on the server (or build the ISO string with +01:00), and fix the comment.
- **L-4. No per-customer flash-sale limit.** One buyer can take the whole flash quota (quantity up to 100). Not required by the spec; flagged for a decision.
- **L-5. A seller can buy their own pack.** `packLines()` does not run the `own_product` check that product lines have (`OrderPricing.php:111`).
- **L-6. Pack "savings" display.** `Pack::recalculateOriginalPrice()` (`:145-157`) uses the product base price, not variant prices or promotions. The figure is display only, but it can look wrong next to a promoted product.
- **L-7. Coupon update skips product checks.** `SellerCouponController::update` (`:177-188`) does not check that the products are active and approved (create does).
- **L-8. Seller promotions can start in the past.** `SellerPromotionController::store` has no `after_or_equal:now` on `starts_at`. It is harmless: the promotion simply starts now.

## Checklist against the brief

| # | Item | Status |
|---|---|---|
| 1 | One backend price source | ✅ `OrderPricing` + `PromotionService`. ⚠️ seller form previews in JS (M-6) |
| 2 | Creation, validation, dates, limits, variants | ✅ mostly. ⚠️ pack price (H-1), timezone depends on device (L-3) |
| 3 | Stacking | ✅ never stacked. ⚠️ coupon all-or-nothing (M-5). Admin = seller pricing ✅ |
| 4 | Order snapshot (guest included) | ✅ amounts frozen, and editing or deleting a promotion never changes an order. ⚠️ type and pack fields missing (M-4) |
| 5 | Server-side validation | ✅ re-priced at placement, `expected_total` → 409, flash sold out → 422, coupon total limit locked. ⚠️ per-customer limit race (M-2), coupon error message (M-3) |
| 6 | Intigo COD per parcel | ✅ `ParcelPayload::codMillimes` = frozen `cod_amount` = seller items − coupon + client delivery fee. Multi-seller = one parcel each. A COD ≤ 0 is refused. `cod_amount` is never rewritten after checkout |
| 7 | Finance | ✅ commission on the discounted net; payouts and platform profit read the stored snapshot (`FinancialSnapshotService::snapshotParcel` asserts the quote equals the lines). ℹ️ admin promotions are funded by the seller (M-8). ⚠️ tier rule (M-7) |
| 8 | Refunds and returns | ✅ product lines and coupons pro-rata. ❌ packs (C-1). ⚠️ rounding (L-2) |
| 9 | Rounding | ✅ integer millimes in checkout and finance; coupon and pack splits sum exactly. ⚠️ L-1, L-2 |
| 10 | Edge cases | ✅ discount expiring in the cart (live price + "promo ended" flag + 409), flash stock running out (atomic → 422), quantity > 1, variant prices, multi-seller cart with one coupon, last flash units and last coupon use under concurrency, cancel before or after dispatch releases stock and flash units. ❌ coupon not released on cancel (H-2) |

## Phase 2 plan (waiting for approval)

1. **C-1**: migration adding `pack_id` / `pack_group` (+ M-4 snapshot columns), spread the pack money over its component lines, guard legacy pack returns, and add a report command for past pack returns. Existing orders are not changed.
2. **H-1**: validate the pack price on create and update, plus a guard in `OrderPricing`.
3. **H-2**: release the coupon per seller order on cancel, and reclaim or refuse it on re-open.
4. **M-1 to M-3**: soft-deactivate used coupons; lock the per-customer limit in `redeem()`; add a `CouponExhausted` 422.
5. **M-5 / M-7 / M-8**: only after your decision.
6. **M-6**: seller preview endpoint (server values only).
7. **L-1 / L-2**: promotion price and return shares in millimes.
8. PHPUnit feature tests for every case above, including the Intigo payload `price` (fake client) and commission, payout and platform figures. Run with `INTIGO_MODE=fake`; no real API calls, no deploy, no push.

Decisions needed from you: **M-5** (skip promoted items vs refuse the coupon), **M-7** (commission tier rule), **M-8** (should the platform fund admin promotions?), **L-4** (per-customer flash limit?).

---

## Phase 2 — fixes applied (2026-11-06)

Owner decisions: M-5 skip promoted items · M-7 bracket from the ORIGINAL price, rate on the FINAL price (all discounts) · M-8 `funded_by` seller|platform on admin promotions · L-4 flash limit per customer (default 2, account or guest phone).

| Finding | Fix |
|---|---|
| C-1 | Pack price spread over every pack line by original price (millimes, remainder on the last line); each line has its own total / commission / seller amount; `pack_id`, `pack_group`, `pack_allocation`, `pack_original_value` stored. Legacy pack orders (pack_id null) can only be returned whole. `php artisan discounts:legacy-pack-returns` lists past partial pack returns (read-only). |
| H-1 | Pack price must be > 0 and < items' current prices, on create AND edit; checkout refuses a pack priced ≤ 0. |
| H-2 | Coupon use released when a parcel is cancelled (ParcelStatus, observer, and the every-minute `promotions:sync` sweep for raw writes); idempotent; re-taken on re-open. |
| M-1 | Coupons soft-deleted (history kept, code can't be applied or recreated). |
| M-2 / M-3 | Total AND per-customer coupon limits re-checked with locking reads inside the checkout transaction; a lost race answers 422 `coupon_unavailable`. |
| M-4 | Order lines store `discount_type` (promotion / flash_sale / pack / coupon / none), `discount_source_id`, `applied_discount_amount`, promotion terms. |
| M-5 | Coupon skips promoted items, refused only when none remains; quote shows applied / skipped items. |
| M-6 | `POST /api/seller/promotions/preview` (same pricing as checkout); the seller form uses it. |
| M-7 | `commission_tier_price` = original unit price; rate applied to the final amount, for promotions, flash sales, packs and coupons. |
| M-8 | Platform-funded: seller paid original − commission on original; `platform_funded_amount` on lines and parcels; shown in admin finance and seller order details. |
| L-1 / L-2 | Promotion price and return shares in integer millimes. |
| L-3 / L-8 | Forms send Tunis time; server reads offset-less dates as Africa/Tunis; seller start can't be > 10 min in the past. |
| L-4 | `promotions.per_customer_limit`, checked at quote and under lock in the transaction. |
| L-5 / L-6 / L-7 | Own pack refused; pack savings from current prices; coupon edit checks product status. |

Tests: `tests/Feature/Discounts/*` (30 tests).

---

## Phase 3 — price change then promotion (2026-11-07)

**Report:** a seller or admin changes a price, then adds a promotion → the promotion is computed on the OLD price.

### Root cause

`PromotionService` applied every discount to the **lowest price of the last 30 days** (`PriceHistory::lowest`, an anti fake-discount rule), not to the current price:

| Place (before the fix) | Effect |
|---|---|
| `PromotionService::priceWith()` / `priceMany()` (`referencePrice()`, `PriceHistory::lowestMany`) | 100 → 150 DT, then 20 % → **80 DT** with 100 crossed out, instead of 120 |
| `PromotionService::finalPriceSql()` (history subqueries) | list filters and sorting used the same old base |
| `PromotionService::preview()` / `zeroPriceProducts()` | seller + admin form previews and the "0 DT" check used the old base, so a fixed amount valid on the new price was refused (and the reverse) |
| `OrderPricing::productLine()` → `original_unit_m` / `tier_m` / `funded_m` | cart, checkout, order lines, seller_orders, Intigo COD, commission bracket and platform-funded payouts all inherited the old base |
| Storefront `PriceDecreaseDialog::discountFor()` and `PromotionModal` fallback | the price-decrease redirect computed the discount from `reference_price` (30-day lowest), so the prefilled fixed amount could give the wrong final price |

Checked and **not** a cause: price writes go straight to `products.price` / `product_variants.price_override` (seller direct edit, admin editor including pending products, legacy admin PUT); no pending-changes table; `promotions` stores only `discount_type` + `discount_value` (no price snapshot); the forms fetch products from the API each time they open. Secondary: deals / promo flyers / home feed display caches (60–120 s) could show an old price briefly.

Side finding (not changed): `products.price` is `DECIMAL(10,2)` while forms accept millimes; 12.345 is stored as 12.35. The old pricing read 12.345 from `product_price_history` (DECIMAL 10,3) and disagreed with the stored price; pricing now always uses the stored value.

### Fix

- One base price: the current `products.price`, or the variant's `price_override`, read at pricing time. Percentage and fixed promotions are applied to it at read time, so changing a price re-prices every running promotion. Commission bracket, platform funding, cart, checkout, order snapshot and Intigo COD follow automatically (all go through `PromotionService` → `OrderPricing`).
- Fixed amounts: creating one ≥ the current price of any product/variant is refused with the prices named (seller + admin); lowering a price to or under a running fixed amount is refused (seller edit, admin editor, legacy admin PUT).
- `App\Support\PricingCache`: version in the deals / promo flyers / home feed cache keys, bumped on every price or promotion change.
- Frontend: seller promotion form prices only from the server preview of the current price, `no-store` fetches, reload on tab focus, stale answers dropped; price-decrease popup computes the discount from the current price; admin form refreshes its server preview on tab focus.
- `php artisan promotions:reprice [--apply]`: dry run lists promotions whose price changes with the new rule; `--apply` refreshes outdated cart `promo_price_snapshot` rows and the price caches. Orders are never touched.
- The 30-day price history is kept only for the seller rule "a live price is lowered through a discount".

Trade-off (follows the owner's request of 2026-11-07): the anti fake-discount protection is gone. A seller can raise a price and then discount it; the badge shows the discount from the raised price.

Tests: `tests/Feature/Discounts/PriceChangePromotionTest.php` (scenarios 1–6 + reprice command); old-rule assertions updated in `PromotionPricingTest`, `PromotionRulesTest`, `ProductChangesTest`.
