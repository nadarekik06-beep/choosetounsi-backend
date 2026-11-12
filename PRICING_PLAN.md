# Pricing plan — "Free delivery everywhere"

Date: 2026-11-10, updated 2026-11-11 with the owner's final decisions (§1). Based on `PRICING_AUDIT.md`.
Implementation in progress. Where an older paragraph below differs from §1, §1 wins.

## 0. The design in one page

- **Parcel size rule (confirmed in the code): the largest item size of the seller_order.**
- New service `App\Services\Pricing\PricingService`, the only place that knows about the included fee:
  - `feeM(size)`: the admin "Client fee" for a size, live from `DeliverySettings` (already cached, flushed on save).
  - `displayM(baseM, size)` = base + fee.
  - `clientBlock(product, variant?, promotion?)`: the price block for the storefront. It holds **display prices
    only** (`price`, `original_price`, `final_price`, `effective_price`, `discount_amount`, `discount_percent`,
    promo fields). No base price, no fee.
  - `badgePercent(oldDisplayM, newDisplayM)` = `floor((old − new) × 100 / old)`.
  - `displayPriceSql(table)`: `finalPriceSql()` + `CASE parcel_size WHEN 'L' THEN ? WHEN 'XL' THEN ? ELSE ? END`
    for SQL filters and sorting.
  - `parcelFees(lines)`: `included_fees_total`, `parcel_size`, `parcel_fee`, `multi_item_discount`, `units`,
    `upsell_min_saving` (= fee S when the parcel has exactly 1 unit). Used by the cart, the quote and the order.
- `PromotionService` keeps returning **base** prices and stays the internal source for checkout, commission,
  coupons, seller and admin screens. Storefront callers switch to `PricingService`. So the checkout and
  commission maths are not rewritten.
- `OrderPricing` keeps its money formula. Per parcel:
  `client_total = Σ base lines − coupon + parcel_fee`, which equals `Σ display lines − coupon − multi_item_discount`.
  The free-delivery branch (`is_free`, contribution) is deleted. `present()` returns display line prices,
  `multi_item_discount`, `delivery_fee = 0`, the "lucky" and upsell data per seller.
- Existing order columns keep their meaning (base amounts), so finance, settlements and old orders are untouched.
  New columns hold the snapshot of the included delivery (see §3).
- Old orders are never recalculated. A new column `seller_orders.pricing_model` (`included` for new orders,
  NULL for old ones) tells every screen which layout to use.

Worked examples with this design (S 9, L 11, XL 17):
A → 110 + 11 = **121.000**, discount 9.000 · B → 60 + 9 = **69.000**, discount 9.000 ·
C → 121.000 + 59.000 = **180.000** · D → refund 121 − 91 = **30.000** · E → S product base 30 shows **40.000**.

## 1. Final decisions (owner, 2026-11-11)

| # | Decision |
|---|---|
| Q1 packs | A pack is **one unit of its largest item size** (per seller when a pack spans sellers). Display = pack price + one fee. No multi-item discount inside a pack; pack + another item of the same seller gets it. |
| Q2 full return | The client gets back **everything they paid** for the parcel (121.000 in example A). Seller at fault (`complaints.shipping_payer = seller`): the seller is charged the **parcel fee + the after-sale return fee** through `seller_adjustments` (new type `return_parcel_fee`, existing `return_shipping`), deducted from the next payouts; what a payout cannot cover stays pending as a debt. Changed mind: today's return-fee deduction from the refund, nothing else for the seller. |
| Q3 | "Changed my mind" keeps today's after-sale return fee deduction (`DeliverySettings::afterSaleReturnFee`, frozen on the complaint). |
| Q4 | Badges are computed from display prices, rounded **down**. Promotion-level labels become "Jusqu'à −X %". Promotion forms show "Le client verra −X %". |
| Q5 | Old orders (`seller_orders.pricing_model` NULL) keep their real delivery amount on every screen. |
| Q6 | `/invoice/{id}` shows client amounts only (display prices, multi-item discount, free delivery) for new orders. |
| Q7 | `products.parcel_size_confirmed_at`: set when a seller or admin saves the size; NULL = flagged in the admin, seller asked to confirm on edit. |
| Q8 | Migration sets `products.delivery_fee = NULL` where it was 0, ids kept in `legacy_free_delivery_products`; sellers notified (in-app + e-mail) by `php artisan pricing:notify-free-delivery-sellers`. |
| Partial return (R7) | Refund = client total before − client total recomputed without the returned units (fees frozen on the order in `seller_orders.fee_rates`). Seller payout / commission move by the base share only. A fee difference on a partial return stays with the platform (only a **full** seller-fault return charges the parcel fee, as decided). |

### Callers of the shared files modified

| Shared code | Callers (all checked) |
|---|---|
| `OrderPricing::forCart / forBuyNow / present` | `CheckoutController` (quote, store, buyNow, priceChanged, orderResponse); `CartController` (per-shop summary) |
| `OrderPricing::promotionEnded` | `CartController` |
| `FinancialSnapshotService::snapshotParcel / checkParcel` | `CheckoutController::placeOrder`; `ParcelStatus`, `Admin/IntigoController` (check only) |
| `DeliverySettings::margins / sellerContribution` | `Admin/DeliverySettingsController`, `Seller/CommissionController` (removed route), `OrderPricing` |
| `ReturnService::applyFinance / refundFor / syncOrder / reverseSellerOrder` | `ReturnService` only (pick-up cash refund, admin refund, create, approve, failed exchange → return) |
| `Order::moneySummary / originalAmounts / applyMoneySummary` | admin: `Admin/OrderController`, `Admin/DashboardController`, `RepairCancelledOrders` (unchanged, base amounts); buyer: `BuyerOrderResource`, `OrderTrackingController`, `ProfileOverviewController`, `OrderPlacedNotification`, `DeliveryDocumentService` (switch to the new `clientMoneySummary()`) |
| `PromotionService::getActivePromotionsFormatted` | `PublicPromotionController`, `PublicSellerController`, `DealsCatalog`, `PromoFlyers` (all public → display prices) |
| `PromotionService::priceMany / priceWith / priceLine / attach` (base, unchanged) | storefront callers move to `PricingService`; `OrderPricing`, `CouponController`, admin / seller controllers, `RepricePromotions`, `DescriptionGenerator`, `ProductReviewResource` stay on base |
| `Product::scopeWhereFinalPrice / scopeOrderByFinalPrice` | `Api/ProductController`, `Api/CategoryController`, `Api/BrandProductController` (all public → display price SQL) |
| `SettlementController::create` | admin settlements only (debt carry-over added) |

Risks I will handle, no answer needed:
- **Open carts and open checkout tabs at deploy time**: carts are priced live, so they refresh on next load; a
  checkout tab opened before the deploy gets the existing 409 "price changed" answer and shows the new quote.
  `carts.promo_price_snapshot` / `pack_price_snapshot` stay base and get the fee added when read.
- **Deploy order**: backend and the two frontends must go out together (an old storefront on the new API would show
  a 0 delivery line and no "lucky" message; amounts stay right).
- **Fee leak**: `effective_delivery_fee` is removed from the product payload. A test checks that no storefront
  endpoint returns a base price or a per-product fee. (The multi-item discount reveals fee sizes by nature.)
- **Admin override of a parcel size before sending to Intigo** changes Intigo's bill, not the client total
  (already true today). The finance page will show the real margin.
- **Display caches** (deals, flyers, home feed): `PricingCache::bump()` is added to the delivery-settings save,
  so Example E is immediate everywhere.
- **Recommendation / forecast / growth-radar scores** keep using base prices (internal, relative values).

## 2. Backend changes (step 1)

### New
| File | Content |
|---|---|
| `app/Services/Pricing/PricingService.php` | as in §0 |
| `app/Http/Middleware/…` none | no global rewriting: each storefront endpoint calls `PricingService` explicitly |

### Pricing and checkout
| File | Change |
|---|---|
| `app/Services/PromotionService.php` | unchanged maths. `getActivePromotionsFormatted()` takes the client block (display prices, display %) and the "up to −X %" label |
| `app/Models/Product.php` | remove `isFreeDelivery()`, `getEffectiveDeliveryFee()`, the two appended attributes; `scopeWhereFinalPrice` / `scopeOrderByFinalPrice` use `displayPriceSql()`; `delivery_fee` no longer fillable |
| `app/Services/Orders/OrderPricing.php` | per line: `size`, `fee_m`, display unit / original / total; per parcel: `parcelFees()`; remove `is_free`, contribution, `shipping_paid_by` logic; `present()` returns display prices, `multi_item_discount`, `delivery_fee: 0`, `lucky` and `upsell` per parcel, totals |
| `Api/Client/CheckoutController.php` | write the new snapshot columns; `orders.shipping_paid_by = 'included'`; order answer carries the discount |
| `app/Services/FinancialSnapshotService.php` | `snapshotParcel()` writes `pricing_model`, `included_fees_total`, `parcel_fee`, `multi_item_discount`, `client_total`, `fee_rates`; contribution 0. Identity check unchanged |
| `app/Services/CouponService.php`, `Api/Client/CouponController.php` | still on base line totals (R4); the preview answer shows display amounts |
| `app/Services/Intigo/ParcelPayload.php` | no change: `price` = `cod_amount` = `client_total`; `package_size` from the largest item. Doc comment updated |
| `app/Console/Commands/Intigo/DryRun.php` | new breakdown wording |

### Client-facing payloads (display prices)
`Api/ProductController.php`, `Api/CategoryController.php`, `Api/SearchController.php`, `Api/BrandProductController.php`,
`Api/Client/CartController.php` (rows, subtotal, per-seller groups with `multi_item_discount` and upsell, for the
selected rows too), `Api/Client/FavoriteController.php`, `Api/PublicPackController.php`, `app/Services/DealsCatalog.php`,
`app/Services/PromoFlyers.php`, `app/Services/Recommendation/ProductCardPresenter.php`,
`app/Services/Chat/ProductRetriever.php` (+ `AiChatController` cheaper / pricier follow-ups read the same display price),
`app/Services/ShopOverview.php` (drop `delivery_fee` and `free_delivery_products`, add `free_delivery: true`),
`Http/Resources/Buyer/BuyerOrderResource.php` + `BuyerOrderItemResource.php` + `OrderTrackingController.php` +
`ProfileOverviewController.php` (display unit prices and line totals, `multi_item_discount`, delivery free for
`pricing_model = included`; old orders unchanged), `Http/Resources/Buyer/BuyerComplaintResource.php` and
`Api/Client/ComplaintController.php` (paid unit price shown on the return form),
`app/Models/Order.php::moneySummary()` (adds `multi_item_discount` and the display subtotal for new orders).

### E-mails and PDFs
`app/Notifications/Buyer/OrderPlacedNotification.php` and `OrderStatusNotification.php` (display lines, discount line,
"Gratuite"), `resources/lang/{fr,en,ar}/buyer_notifications.php`, `emails.php`, `messages.php`;
`app/Services/Ads/AdEmailService.php` + `emails/marketing/_product.blade.php` + `emails/welcome/user.blade.php`
(display prices, no per-seller free-delivery badge); `app/Services/Orders/DeliveryDocumentService.php` +
`pdf/delivery-slips.blade.php` (client amounts); `pdf/order-summary.blade.php` (admin: base and display).

### Free shipping removal (R3)
`Api/ProductController.php` (filter), `Api/Seller/SellerProductController.php` (`parseDeliveryFee` removed, field
ignored), `Api/Seller/CommissionController.php` + route `GET /seller/shipping-cost` (removed),
`app/Services/AdminProductEditor.php`, `Admin/ProductController.php`, `Http/Resources/Admin/ProductReviewResource.php`
(`free_delivery` removed; `display_price` and `parcel_size_confirmed` added), `app/Services/Delivery/DeliverySettings.php`
(contribution no longer read or written; `margins()` = client fee − Intigo cost), `Admin/DeliverySettingsController.php`
(contribution field refused, free-delivery margin and warning removed, `PricingCache::bump()` on save),
`Api/Seller/EarningsController.php`, `Api/Seller/SellerOrderController.php` (no deduction on new orders; old orders keep
their stored charge), `app/Services/Returns/ReturnService.php` (`TYPE_FREE_DELIVERY` adjustment only for old orders).

### Finance (R6)
`Admin/FinanceController.php` (orders list, order detail, overview, CSV): per order **client paid, seller payout,
commission, delivery collected (`parcel_fee`), Intigo cost, delivery margin, multi-item discount given**; the
"Seller delivery contributions" KPI becomes history-only. `Admin/OrderController.php`, `Admin/IntigoController.php`
(margin = parcel fee − cost). Seller side: payout and prices stay base; no "lucky" line.

### Returns (R7) — `app/Services/Returns/ReturnService.php`
- `applyFinance()` / `reverseSellerOrder()`: after reversing the lines (base share, unchanged), recompute the parcel
  from the lines that remain, with the per-size fees frozen on the order (`fee_rates`): new `parcel_fee`,
  `included_fees_total`, `multi_item_discount`, `client_total`, `delivery_fee`, `platform_delivery_margin`.
- `items_amount` (client refund before the after-sale fee) = `old client_total − new client_total`.
- Seller payout and commission move by the item's base share only (today's code).
- `syncOrder()` reads the new `client_total`. Exchanges and refused parcels: untouched.

## 3. Migrations

1. `2026_11_10_000001_add_included_delivery_snapshot.php`
   - `order_items`: `parcel_size` (S/L/XL, null), `included_fee` DECIMAL(12,3) null (per unit),
     `display_unit_price` DECIMAL(12,3) null, `display_original_unit_price` DECIMAL(12,3) null.
     (`unit_price`, `original_unit_price`, `discount_amount`, `net_total`, commission columns already hold the base snapshot.)
   - `seller_orders`: `pricing_model` VARCHAR(20) null, `included_fees_total`, `parcel_fee`, `multi_item_discount`,
     `client_total` DECIMAL(12,3) null, `fee_rates` JSON null.
     (`delivery_fee` = parcel fee, `shipping_cost` / `agency_delivery_cost` = Intigo cost, `commission_amount`,
     `seller_net_amount` = payout, `platform_delivery_margin`, `cod_amount` already exist.)
   - `orders`: `multi_item_discount` DECIMAL(12,3) default 0.
   - `products`: `parcel_size_confirmed_at` TIMESTAMP null (Q7).
2. `2026_11_10_000002_remove_seller_free_delivery.php`
   - backup table `legacy_free_delivery_products (product_id, noted_at)`, then `products.delivery_fee = NULL`
     where it was 0.
   - No column is dropped. `seller_contribution`, `seller_shipping_charge`, `is_free_delivery`, `shipping_paid_by`
     stay for old orders. No order row is touched.

All additive and nullable: safe to run before the code switch, and `down()` restores the flags from the backup table.

## 4. Admin panel (step 2)

| File | Change |
|---|---|
| `app/(dashboard)/delivery-fees/page.tsx`, `lib/deliverySettingsApi.ts` | remove "Seller contribution" and "Margin · free delivery"; one margin = client fee − Intigo cost; note "the client fee is included in every product price, live" |
| `app/(dashboard)/finance/FinancePage.tsx`, `FinanceOrderDrawer.tsx` | new columns (client paid, payout, commission, delivery collected, Intigo cost, margin, multi-item discount); contributions shown only on old orders |
| `app/(dashboard)/products/page.tsx`, `[id]/_components/ReviewTabs.tsx`, `SummaryPanel.tsx` | base price **and** display price; "size not confirmed" flag and filter |
| `[id]/edit/_components/ProductEditorForm.tsx`, `editorModel.ts`, `VariantsEditor.tsx`, `lib/api/productEditor.ts`, `types/productReview.ts` | remove `free_delivery`; live "client sees X" next to the base price and each variant; parcel size stays editable |
| `app/(dashboard)/orders/page.tsx`, `components/intigo/ParcelIntigoBlock.tsx`, `lib/intigoApi.ts` | parcel breakdown: display subtotal, multi-item discount, COD, parcel fee, Intigo cost, margin |
| `app/(dashboard)/promotions/page.tsx` | preview shows base result and what the client sees (price and real %) |

## 5. Storefront and seller dashboard (step 3)

Storefront (values from the API only, no price maths in JS):
| File | Change |
|---|---|
| `app/components/layout/Navbar.tsx` + `messages/*` | top bar alternates "🚚 LIVRAISON GRATUITE PARTOUT EN TUNISIE" and the current text (fade every few seconds, single line on mobile) |
| new `app/components/product/FreeDeliveryBadge.tsx` | small truck badge, green `#198f41`; used in `ProductCard.tsx`, `app/products/[slug]/page.tsx`, deals cards, pack cards, chat cards, favourites |
| `app/components/promotions/ProductPrice.tsx` | uses the server `discount_percent` only (the JS fallback is removed) |
| `components/CartDrawer.tsx`, `context/CartContext.tsx`, `lib/shopApi.ts` | rows grouped by shop; green 🎉 message, upsell hint, "Remise multi-articles" line, "Livraison : Gratuite"; totals come from the API for the selected rows |
| `app/checkout/page.tsx`, `components/checkout/OrderConfirmModal.tsx` | same messages per shop, discount line, delivery "Gratuite" in green, no amount |
| `app/orders/page.tsx`, `app/profile/_components/OrdersTab.tsx`, `OverviewTab.tsx`, `app/order/track/[token]/page.tsx`, order confirmation | discount line and "Gratuite" for new orders; old orders unchanged |
| `app/products/[slug]/page.tsx`, `app/deals/[slug]/page.tsx`, `app/deals/_components/*`, `app/components/sections/PacksSection.tsx`, `FlashDealsSection.tsx`, `app/sellers/[id]/page.tsx`, `app/shop/_components/Catalog.tsx`, `Closing.tsx`, `app/components/filters/ProductFilterSidebar.tsx`, `lib/shopPageApi.ts` | remove per-seller free-delivery badges, filters, chips and "delivery fee" texts; pack savings from API values |
| `components/chat/ChatProductCard.tsx`, `app/search/page.tsx`, `app/category/[slug]/page.tsx`, `app/favorites`, `app/components/ProductRecommendations.tsx` | no logic change: they render the API block; checked one by one |
| `app/components/returns/*` | refund preview from the API |

Seller dashboard:
| File | Change |
|---|---|
| `app/seller/products/ProductModal.tsx`, `lib/sellerApi.ts`, `types/seller.ts` | remove `FreeDeliveryToggle` and `shippingApi`; add the info line « Le prix affiché au client inclut la livraison, gérée par ChooseTounsi. Vous recevez votre prix moins la commission. » |
| `app/seller/earnings/EarningsPage.tsx`, `earnings/receipt/page.tsx`, `app/settlement/[id]/page.tsx` | same info line; free-shipping deduction column shown only when an old order has one |
| `app/seller/orders/page.tsx`, `app/seller/components/SellerOrderDrawer.tsx` | base prices and payout; no "lucky" discount; "paid by you" block only for old orders |
| `app/seller/promotions/PromotionModal.tsx` | "le client verra −X %" from the server preview (Q4) |
| `app/invoice/[id]/page.tsx` | per Q6 |
| `messages/{fr,en,ar}.json` | new keys (top bar, badge, lucky, upsell, discount line, info line); remove the unused free-shipping keys |

## 6. Tests (Laravel feature tests, `tests/Feature/Pricing/`)

1. Example A: display prices, discount 9.000, client total 121.000, COD in the Intigo payload 121.000,
   payout 110.000 − commission, delivery collected 11.000, Intigo cost 11.000, margin 0.
2. Example B: quantity 2 → 78.000 − 9.000 = 69.000; payout 60.000 − commission.
3. Example C: two sellers → 121.000 + 59.000 = 180.000, two parcels, upsell on seller B only.
4. Example D: partial return → refund 30.000; payout and commission down by the T-shirt's base share.
   Plus: returning the largest item, and a full return (per Q2).
5. Example E: admin sets S to 10.000 → product, list, search, cart and filter show 40.000; an older order keeps
   its amounts.
6. Badge %: percentage and fixed promotions, flash sale, variant price; "−50 %" really halves the crossed-out price.
7. Coupon + multi-item: coupon on the base part only, promoted items skipped, discount still 9.000.
8. Pack alone and pack + item (per Q1).
9. Guest checkout: same totals, tracking page payload, e-mail rows.
10. SQL price filter and sort agree with the PHP display price (S, L, XL, promoted).
11. Leak test: no storefront endpoint returns a base price, `effective_delivery_fee` or a delivery amount.
12. Seller endpoints still return base prices and unchanged payouts with and without the discount.
13. Old order (legacy snapshot) still renders with its delivery fee; legacy free-delivery order keeps its charge.
14. Chatbot retriever: prices, min/max filters and price ranges are display prices.

Existing tests to update or retire: `Delivery/CheckoutDeliveryFeesTest`, `Delivery/DeliverySettingsAdminTest`,
`Delivery/PackParcelsTest`, `Shipping/FreeShippingFinanceTest` (retired, legacy case kept),
`Packs/PackFreeDeliveryTest` (retired), `Discounts/*`, `Promotions/*`, `Orders/ReturnFlowTest`,
`Orders/GuestCheckoutTest`, `Orders/DeliveryDocumentsTest`, `Intigo/IntigoSendPayloadTest`,
`Intigo/IntigoDryRunScenarioTest`, `Buyer/BuyerOrderPrivacyTest`, `Admin/AdminProductEditorTest`,
`Ads/MarketingEmailTest`, `Shop/ShopOverviewTest`.

Then: full `php artisan test`, `npm run build` in `choosetounsi-frontend` and `admin-panel`
(the seller dashboard is part of the storefront project). Local only, no deploy, no push.

## 7. Order of work

1. Backend: migrations → `PricingService` → `OrderPricing` / checkout / snapshot → client payloads → returns →
   finance → e-mails / PDFs → free-shipping removal → tests.
2. Admin panel.
3. Storefront, then seller dashboard.
4. Full test run, three builds, summary with files changed, migrations and the browser checklist.
