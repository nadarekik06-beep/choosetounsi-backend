# Pricing audit — "Free delivery everywhere"

Date: 2026-11-10 · Read-only audit, no code changed.
Projects read: `choosetounsi-backend` (Laravel 8), `choosetounsi-frontend` (storefront + `/seller`), `admin-panel`.
`choosetounsi-ai-service` (Python) holds no price: nothing to change there.

## 1. Main findings

1. **The money does not change, only the presentation.** Today a normal parcel costs the client
   `items − coupon + client fee of the parcel size`. The new rule gives
   `Σ(base + fee) − coupon − (Σ fees − parcel fee)`, which is the same number. So the COD amount,
   commission, seller payout, Intigo cost and delivery margin of the new model are exactly
   today's "client pays delivery" path. Example A today already gives 121.000.
2. **Commission and seller payout are already computed on the seller's price**, never on delivery
   (`CommissionService::breakdown`, `OrderPricing::build`). R6 is already true for normal parcels.
3. **Parcel size rule: the largest item size** (`ParcelSize::largest()`, called in
   `OrderPricing.php:413`, stored in `seller_orders.parcel_size`, sent as Intigo `package_size`
   by `ParcelPayload.php:140`: S = 1, L = 4, XL = 5). The assumption in the brief is correct.
4. **The COD sent to Intigo is the frozen `seller_orders.cod_amount`** (`ParcelPayload::codMillimes`).
   It is never recomputed after checkout.
5. **The storefront already displays API values** through one component (`ProductPrice.tsx`) and one
   backend block (`PromotionService::pricing()`). The work is: make that block return display prices
   on client endpoints, and replace about 15 raw `'price' => $product->price` fields.
6. **What really changes for money: returns.** Today a return refunds the items only; the delivery
   fee is never given back. R7 changes that (see §7 and the questions in the plan).

## 2. How delivery settings are stored

| What | Where |
|---|---|
| Per-size rates | table `delivery_size_rates` (S, L, XL): `client_fee`, `agency_cost`, `return_fee`, `seller_contribution`, `after_sale_return_fee`, `after_sale_use_agency_cost`, `exchange_fee` |
| Flat row | `delivery_settings` id 1: mirror of the S values + `refused_parcel_fee_paid_by`, `intigo_can_open`, `intigo_is_exchange` |
| Change log | `delivery_setting_changes` |
| Service | `app/Services/Delivery/DeliverySettings.php`: integer millimes, `Cache::rememberForever('delivery_settings.v4')`, `flush()` on every `update()` |
| Admin API | `GET/PUT /api/admin/delivery-settings` → `Admin/DeliverySettingsController.php` (margins `normal` and `free_delivery`, warnings) |
| Admin UI | `admin-panel/app/(dashboard)/delivery-fees/page.tsx`, `lib/deliverySettingsApi.ts` |

The cache and its invalidation already exist. The price display caches (deals, flyers, home feed)
use `App\Support\PricingCache::bump()`, which is **not** called when delivery settings change today.

`products.parcel_size` is `ENUM('S','L','XL') NOT NULL DEFAULT 'S'` (migration 2026_10_21_000001).
A product can never have "no size": products that existed before that migration all got S silently.
`products.price` is `DECIMAL(10,2)`; `product_variants.price_override` is cast `decimal:3`.

## 3. Where prices are computed (backend)

| Role | File | Notes |
|---|---|---|
| Promotion price block (single source) | `app/Services/PromotionService.php` `pricing()`, `priceMany()`, `priceLine()`, `priceWith()`, `getEffectivePrice()`, `basePrice()` | returns `original_price`, `final_price`, `effective_price`, `discount_amount`, `discount_percent`, `promo_type`, `promotion`. Base prices today |
| Badge % | `PromotionService::percentOff()` | a percentage promotion shows **its own rate** (e.g. 50), a fixed one is computed. Must become display-based (R4) |
| SQL price for filter / sort | `PromotionService::finalPriceSql()`, `Product::scopeWhereFinalPrice`, `scopeOrderByFinalPrice` | base price after promotion; no delivery fee |
| Order money (single source) | `app/Services/Orders/OrderPricing.php` | lines, packs, coupons, commission, parcel size, delivery fee, COD, payout, margin; `present()` is the storefront payload |
| Commission | `app/Services/CommissionService.php` | bracket from the original unit price, rate on the final amount, never on delivery |
| Coupons | `app/Services/CouponService.php` | on base line totals of non-promoted product lines |
| Order writer | `Api/Client/CheckoutController.php` `placeOrder()`, `writeProductLine()`, `writePackLine()` | `order_items` + `seller_orders` snapshot |
| Parcel snapshot | `app/Services/FinancialSnapshotService.php` `snapshotParcel()`, `checkParcel()` | identity: `subtotal − discount + delivery_fee = payout + commission + agency + margin` |
| Live order totals | `app/Models/Order.php` `moneySummary()`, `originalAmounts()` | `subtotal − discount + shipping_fee` from live parcels |
| Returns | `app/Services/Returns/ReturnService.php` `share()`, `applyFinance()`, `reverseSellerOrder()`, `syncOrder()`, `refundFor()` | see §7 |

### Client-facing endpoints that return a price (must return display prices)

| Endpoint / use | File |
|---|---|
| Product list, filters, sort, detail, variants, by-ids, featured | `Api/ProductController.php` (`index` 78-82, 115, 170-171; `show` 402-417, 531; `byIds` 606-637; 665) |
| Category pages | `Api/CategoryController.php` (37, 128-129, 185, 194) |
| Search text / image / cards / price filter | `Api/SearchController.php` (71-80, 246-307) |
| WearTounsi products | `Api/BrandProductController.php` (47-48, 107-121) |
| Cart | `Api/Client/CartController.php` (170-173, 440-490, 496-525, `index` subtotal / savings) |
| Favourites | `Api/Client/FavoriteController.php` (114-142) |
| Coupon preview | `Api/Client/CouponController.php` (63-87) |
| Checkout quote / order answer | `Api/Client/CheckoutController.php` + `OrderPricing::present()` |
| Packs list / detail | `Api/PublicPackController.php` (26-35, 57, 89-90, 120-126, 144, 159) |
| Deals, flash sales, seller storefront promotions | `PromotionService::getActivePromotionsFormatted()` (549, 570-572), `app/Services/DealsCatalog.php` (61-72, 181-196), `app/Services/PromoFlyers.php` (101-102) |
| Home sections, recommendations, related products | `app/Services/Recommendation/ProductCardPresenter.php` (71) |
| Buyer orders | `Http/Resources/Buyer/BuyerOrderResource.php`, `BuyerOrderItemResource.php`, `Api/Client/ClientOrderApiController.php`, `ProfileOverviewController.php` (69, 101) |
| Guest tracking | `Api/Client/OrderTrackingController.php` `payload()` (item `total` = `net_total`) |
| Returns (client) | `Http/Resources/Buyer/BuyerComplaintResource.php`, `Api/Client/ComplaintController.php` (paid unit price on the return form) |
| Chatbot | `app/Services/Chat/ProductRetriever.php` (base, variant min, final, min/max filters, sort), `ReplyComposer.php` (allowed amounts), `Api/AiChatController.php` (cheaper / pricier follow-ups) |
| Shop page stats | `app/Services/ShopOverview.php` (41, 47: `delivery_fee`, `free_delivery_products`) |
| Order e-mails (buyer) | `app/Notifications/Buyer/OrderPlacedNotification.php` (98-112), `OrderStatusNotification.php`, `resources/lang/*/buyer_notifications.php` |
| Marketing e-mails | `app/Services/Ads/AdEmailService.php` (151, 177), `resources/views/emails/marketing/_product.blade.php`, `emails/welcome/user.blade.php` |
| Parcel slip (client sees it) | `resources/views/pdf/delivery-slips.blade.php` (144-161), `app/Services/Orders/DeliveryDocumentService.php` (78-90) |
| Product model serialization | `app/Models/Product.php`: `price` is serialized as is; `$appends` adds `is_free_delivery` and `effective_delivery_fee` (this one exposes the fee, so the client could work out the base price) |

### Seller / admin (stay on base prices)

`Api/Seller/*` (SellerProductController, SellerPackController, SellerPromotionController, SellerCouponController,
EarningsController, SellerOrderController, SellerInvoiceController, CommissionController, analytics, AI tools),
`Admin/*` (ProductController, AdminProductEditor, ProductReviewResource, AdminPromotionController, AdminPackController,
FinanceController, OrderController, IntigoController), `resources/views/pdf/order-summary.blade.php` (admin PDF),
seller order e-mails (`emails/seller-orders/*`), forecast / growth radar / visitor insights / recommendations
(internal scores on base price).

## 4. Free-shipping traces (R3)

### Backend
| File | Trace |
|---|---|
| `app/Models/Product.php` 39, 54, 312-346 | `delivery_fee = 0` means seller-offered free delivery; `isFreeDelivery()`, appended `is_free_delivery`, `effective_delivery_fee` |
| `Api/ProductController.php` 117-120, 208 | `free_delivery` list filter |
| `Api/Seller/SellerProductController.php` 350, 490, 854-858 | `parseDeliveryFee()` on create / update |
| `Api/Seller/CommissionController.php` 72-79, route `GET /seller/shipping-cost` | what free delivery costs the seller |
| `app/Services/AdminProductEditor.php` 88, 161, 371, 504, 700, 840 | `free_delivery` field of the admin editor |
| `Admin/ProductController.php` 181, 187 | `delivery_fee` accepted on the legacy admin update |
| `Http/Resources/Admin/ProductReviewResource.php` 306-307, 374-376 | free / default delivery in the review screen |
| `Api/PublicPackController.php` 57, 125-126 · `Api/Client/CartController.php` 490 | `is_free_delivery` on packs and cart rows |
| `app/Services/Orders/OrderPricing.php` 167, 237, 259, 417-421, 436, 443-453, 462-463 | `is_free`, `seller_contribution`, `contribution_m`, `shipping_paid_by` |
| `app/Services/Delivery/DeliverySettings.php` | `seller_contribution` / `seller_free_delivery_contribution`, `margins()['free_delivery']` |
| `Admin/DeliverySettingsController.php` 30, 122-123, 141, 148 | contribution field, free-delivery margin and warning |
| `app/Services/FinancialSnapshotService.php` 124, 128-129, 232-235 | `seller_shipping_charge`, `seller_free_delivery_contribution`, `is_free_delivery`, `shippingPayer()` |
| `Admin/FinanceController.php` 68, 110, 252-262, 439-447, 486, 546, 618, 644 | seller shipping / contribution columns and KPI |
| `Api/Seller/EarningsController.php` 35, 54, 131, 204, 236, 246-247, 352-353, 402 · `SellerOrderController.php` 200, 256, 266 | contribution deducted from the seller |
| `Admin/OrderController.php` 218, 221 · `Admin/IntigoController.php` 246-247 | seller shipping, free-delivery margin |
| `app/Services/Returns/ReturnService.php` 966, 974-975, 1010-1019 · `app/Models/SellerAdjustment.php` 24 | contribution still charged on a full return (`TYPE_FREE_DELIVERY`) |
| `app/Services/ShopOverview.php` 41 · `app/Services/Ads/AdEmailService.php` 151, 177 · `emails/marketing/_product.blade.php` · `resources/lang/*/emails.php` | "free delivery" product counts and badges |
| `app/Console/Commands/Intigo/DryRun.php` 106-109, 144 · `resources/views/pdf/order-summary.blade.php` 75, 118 · `pdf/delivery-slips.blade.php` 160 | free-delivery wording in admin tools and slips |
| Columns | `products.delivery_fee`; `seller_orders.is_free_delivery`, `seller_shipping_charge`, `seller_free_delivery_contribution`; `orders.shipping_paid_by`; `delivery_size_rates.seller_contribution`; `delivery_settings.seller_free_delivery_contribution` |

No coupon type and no promotion type is about free shipping (checked `Coupon`, `Promotion`, their controllers).

### Storefront and seller dashboard (`choosetounsi-frontend`)
| File | Trace |
|---|---|
| `app/seller/products/ProductModal.tsx` 136-205, 280, 421-424, 881-882, 1257-1260 | `FreeDeliveryToggle`, `free_delivery` form field |
| `lib/sellerApi.ts` 145, 279, 321-326 · `types/seller.ts` 299-300 | `delivery_fee` field, `shippingApi.cost()` |
| `app/components/filters/ProductFilterSidebar.tsx` 37-65, 262-263 · `app/shop/_components/Catalog.tsx` 52, 74, 94 | "free delivery" filter |
| `app/sellers/[id]/page.tsx` 251-259, 307, 395 | "Livraison gratuite" chip filter |
| `app/products/[slug]/page.tsx` 101, 699-714, 798-800 · `app/deals/[slug]/page.tsx` 61, 761-762 | per-product / per-pack free-delivery badge and trust block |
| `components/CartDrawer.tsx` 32, 132-142, 584-602 | row badge, "all free" banner, "delivery calculated at checkout" |
| `app/checkout/page.tsx` 97, 1427-1435 · `components/checkout/OrderConfirmModal.tsx` 209, 237 | delivery amount per parcel |
| `app/orders/page.tsx` 692, 711 · `app/order/track/[token]/page.tsx` 202 | delivery line with an amount |
| `app/shop/_components/Closing.tsx` 37-38 · `lib/shopPageApi.ts` 18-19 | "Livraison X DT · N produits livrés gratuitement" |
| `app/seller/orders/page.tsx` 185-198, 499-506 · `app/seller/components/SellerOrderDrawer.tsx` 84, 386-392 · `app/seller/earnings/EarningsPage.tsx` 427 · `app/seller/earnings/receipt/page.tsx` · `app/settlement/[id]/page.tsx` 18, 224, 237 | free-shipping deduction shown to the seller |
| `messages/{fr,en,ar}.json` | about 20 keys each (`freeDelivery*`, `allFreeDelivery`, `shippingAtCheckout`, `chips.freeShipping`, `shippingYou*`, `deliveryPerShop`, `parcelSizeTitle`, …) |

### Admin panel
| File | Trace |
|---|---|
| `app/(dashboard)/delivery-fees/page.tsx` 35, 48, 75, 114, 144, 156, 182, 203 · `lib/deliverySettingsApi.ts` | "Seller contribution" column, "Margin · free delivery" column |
| `app/(dashboard)/finance/FinancePage.tsx` 513, 535-536, 614, 630 · `FinanceOrderDrawer.tsx` 77-83, 296-300, 444-445, 459 | seller delivery contributions |
| `app/(dashboard)/products/[id]/edit/_components/ProductEditorForm.tsx`, `editorModel.ts`, `lib/api/productEditor.ts`, `types/productReview.ts`, `ReviewTabs.tsx`, `SummaryPanel.tsx` | `free_delivery` editor field and review labels |
| `app/(dashboard)/orders/page.tsx`, `components/intigo/ParcelIntigoBlock.tsx`, `lib/intigoApi.ts` | free-delivery margin / labels |

## 5. Intigo: package size and COD today

- `package_size` = `ParcelSize::packageSize($so->parcel_size)`. `parcel_size` is the largest item size at
  checkout (`parcel_size_computed` keeps the computed value; the admin may override `parcel_size` before sending).
- `price` (COD) = `seller_orders.cod_amount` = `items − coupon + delivery fee` frozen at checkout
  (0 for prepaid orders, which are not sent). A COD ≤ 0 is refused.
- One `seller_order` = one parcel, `cid = CT-SO-{id}`. Only admin-confirmed parcels are sent.
- Refused parcel: `ParcelStatus.php:363` charges `refusedFee(parcel size)` to the payer set in the
  delivery settings. **Not touched by this project.**

## 6. Commission and payouts today

- Per line: `seller_amount = base − rate × base`, `commission = net − seller_amount`; bracket from
  `commission_tier_price` (original unit price), plan reductions and overrides in `CommissionService`.
- Per parcel: `seller_net_amount = Σ seller_amount − seller_shipping_charge` (the charge is the
  free-delivery contribution, 0 on normal parcels); `platform_profit = commission + delivery margin`;
  `platform_delivery_margin = delivery_fee − agency_cost` (normal) or `contribution − agency_cost` (free).
- Finance pages read the stored columns only (`FinanceController`, `EarningsController`, settlements).

## 7. Refunds today

- `ReturnService::applyFinance()` reverses the returned units line by line (`share()`: gross, coupon
  share, net, commission, seller amount, in millimes) and lowers `seller_orders.subtotal / discount /
  commission / seller_net_amount`. `syncOrder()` recomputes the order total.
- **Refund = net of the returned items − the after-sale return fee when the client pays it**
  ("changed mind"), never below 0. Seller at fault: the fee goes on the seller's next settlement.
- **The outbound delivery fee is never refunded**, even on a full return (`seller_orders.delivery_fee`
  is not touched by a return).
- Cancellation is always a whole parcel (no per-item cancel exists). For COD nothing was paid yet.
- Exchanges: `exchange_fee` per size, cash collected by the courier or charged to the seller.

## 8. Frontend price display

- One component: `app/components/promotions/ProductPrice.tsx` (`promoPricing()` reads `final_price`,
  `original_price`, `discount_percent`; it falls back to `Math.round` of the ratio only when the API
  sends no percent).
- Cart: `components/CartDrawer.tsx` sums `line_total` of the selected rows in JS (line 411). The cart
  has no per-seller grouping or quote today.
- Checkout: `app/checkout/page.tsx` shows the server quote and sends `expected_total`.
- JS price maths left: `app/deals/_components/model.ts:92-93` (coupon %), `app/seller/promotions/CouponModal.tsx`
  (seller preview on base price: fine), `app/components/sections/PacksSection.tsx` (pack savings).
- SEO: no price in `generateMetadata` (`app/products/[slug]/layout.tsx`), `app/sitemap.ts` or the home
  JSON-LD. Nothing leaks a base price there.
- Top bar: `app/components/layout/Navbar.tsx:891`, key `nav.announcement` ("🇹🇳 Des vendeurs 100 % tunisiens…").
- `app/invoice/[id]/page.tsx` is the **seller's** invoice (opened from `/seller/orders`); it shows base
  prices and a shipping line. If sellers print it and put it in the parcel, the client would see base prices.

## 9. Existing tests that encode today's rules

`tests/Feature/Delivery/CheckoutDeliveryFeesTest.php`, `DeliverySettingsAdminTest.php`, `PackParcelsTest.php`,
`tests/Feature/Shipping/FreeShippingFinanceTest.php`, `tests/Feature/Packs/PackFreeDeliveryTest.php`,
`tests/Feature/Discounts/*`, `tests/Feature/Promotions/*`, `tests/Feature/Orders/ReturnFlowTest.php`,
`ExchangeFlowTest.php`, `GuestCheckoutTest.php`, `CancelledOrderMoneyTest.php`, `DeliveryDocumentsTest.php`,
`tests/Feature/Intigo/IntigoSendPayloadTest.php`, `IntigoDryRunScenarioTest.php`,
`tests/Feature/Buyer/BuyerOrderPrivacyTest.php`, `tests/Feature/Admin/AdminProductEditorTest.php`,
`tests/Feature/Ads/MarketingEmailTest.php`, `tests/Feature/Shop/ShopOverviewTest.php`.
Tests run on MySQL database `choosetounsi_test` with `CACHE_DRIVER=array` and `INTIGO_MODE=fake`.
