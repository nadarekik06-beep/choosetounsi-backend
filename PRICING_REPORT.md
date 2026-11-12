# "Free delivery everywhere": implementation report

Local only. Nothing deployed, nothing pushed, no migration run on real data (only on `choosetounsi_test`).

## How it works

- `App\Services\Pricing\PricingService` is the single place that knows about the included delivery.
  display price = seller price (after promo) + live admin "Client fee" of the product's parcel size. Storefront
  endpoints return display prices only (no base price, no fee). `PromotionService` still prices the seller's
  amount for checkout, commission, coupons, seller and admin screens.
- Checkout money is unchanged: client pays `Σ base − coupon + ONE parcel fee` = `Σ display − coupon − multi-item discount`.
  That figure is `cod_amount`, sent to Intigo as is. Commission and payout never touch delivery.
- New orders carry `pricing_model = 'included'` (orders / seller_orders) plus a snapshot (`included_fees_total`,
  `parcel_fee`, `multi_item_discount`, `client_total`, `fee_rates`; per item `parcel_size`, `included_fee`,
  `display_unit_price`…). Older orders have NULL there and keep every screen exactly as before (their real delivery amount).
- Returns recompute the parcel "as if the returned units had never been ordered" with the frozen fees.

## Migrations (run in this order)

1. `2026_11_11_000001_add_included_delivery_snapshot` (additive, nullable, reversible; marks L / XL products as size-confirmed)
2. `2026_11_11_000002_remove_seller_free_delivery` (backup table `legacy_free_delivery_products`, then `products.delivery_fee` 0 → NULL; `down()` restores from the backup)

After deploying: `php artisan pricing:notify-free-delivery-sellers` (dry run), then `--send` once (bell + e-mail, FR/EN/AR).

## Tests

- Baseline before any change: 1011 tests, 1 failure that already existed
  (`PromotionPricingTest::test_jeans_promotion_shows_on_every_product_endpoint`, "same category (similar)": the
  related-products endpoint does not return the product; unrelated to this task, not fixed).
- New: `tests/Feature/Pricing/FreeDeliveryExamplesTest.php` (examples A–G, quantity, two sellers, partial / full
  returns, seller debt carry-over, promo badge, variant, coupon, pack Q1, guest, "Mes commandes", old order, SQL sort)
  and `FreeDeliveryRulesTest.php` (no base price leak, seller screens, invoice, admin settings, Q7, Q8 migration + notice,
  promotion preview, cart summary, return preview, chatbot).
- Old tests that encoded the removed rules were rewritten (delivery included, old flag ignored, refunds include the
  parcel fee on full returns); see git diff of `tests/`.
- Storefront and admin panel: `next build` passes; FR / EN / AR message keys have identical sets.

## Decisions I made where the brief was silent

- Partial return that changes the parcel's largest item (example D variant, shoes returned): the client gets the fee
  difference back (82.000), the platform carries it; only a **full** return at the seller's fault charges the seller the parcel fee.
- A 100 % coupon leaves the client paying the parcel fee (coupons apply to the base price only).
- Seller debt: a settlement never pays below 0; debits it cannot cover stay pending (split in two rows) and come off the next payouts.
- `GET /seller/shipping-cost` kept for older dashboards, now returns zeros. `?free_delivery=1` on products is accepted and ignored.
- The admin editor field `free_delivery` is gone; an old admin build still sending `seller_contribution` is accepted and ignored.

## Bugs / loose ends outside this scope (not fixed)

- Related products ("similar") test failure above.
- `docs`: `AUDIT_DISCOUNTS.md` / `PRICING_AUDIT.md` still describe the old model.
- Unused i18n keys (`freeDelivery*`, `shippingYou*`, …) remain in the storefront messages; harmless.
- Old orders' returns still never refund delivery (by design, Q5).

## Manual browser checklist

1. Top bar alternates "LIVRAISON GRATUITE PARTOUT EN TUNISIE" and the Tunisian-sellers line; one line on a phone; AR is RTL.
2. A product card and a product page show the truck badge (green) and the displayed price = seller price + fee of its size.
3. Admin → Delivery & fees: only Client fee / Intigo cost / Intigo return fee + one margin. Change S 9 → 10: refresh a storefront S product, +1 DT at once.
4. Add T-shirt (S) + shoes (L) from one shop: cart and checkout show the green 🎉 message, "Remise multi-articles", delivery "Gratuite", total = 121.000 for the 30 + 80 example.
5. Cart with a single item: the "add another item, save at least …" hint. Tick only some rows: totals follow (server).
6. Place a COD order (logged in and guest): confirmation, e-mail, "Mes commandes", guest tracking page, parcel slip PDF all show free delivery + the discount line.
7. Admin confirms → seller prepares → parcel goes to Intigo with the same COD; webhook → delivered; bordereau upload / download unchanged.
8. Returns: request a partial and a full return (seller fault, then "changed my mind"); the form's refund estimate matches the refund; seller finance shows the parcel fee + return fee debit.
9. Seller dashboard: product form has the green info line and no free-delivery switch; an unconfirmed-size product asks to pick the size; promotion form shows "Le client verra −X %"; earnings page shows the info line and no delivery deduction.
10. Admin products: "Size not confirmed" badge + filter, "Client sees" next to the price; Finance order drawer shows client paid / delivery collected / Intigo cost / margin / multi-item discount.
11. A pre-existing order still shows its real delivery amount in "Mes commandes".
