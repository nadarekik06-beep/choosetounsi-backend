# Exchange-first returns & refunds: final report

Local only. Nothing committed, pushed or deployed. Migrations were run only on `choosetounsi_test` (and rolled back / re-applied to prove `down()`).

## Results
- Baseline before any change: 1050 tests, 1 failure (the known `PromotionPricingTest::test_jeans_promotion_shows_on_every_product_endpoint`).
- Final full suite, run alone on the final code: **1079 tests, 1078 passed, same single known failure.**
  New tests: `tests/Feature/Returns/ReturnsPolicyV2Test.php` (28), `GuestReturnsPolicyV2Test.php` (1), and 1 chatbot KB test in `tests/Feature/Chat/ChatEndpointTest.php`. Chatbot folder alone: 97 tests green.
- `next build` OK for the storefront (seller dashboard included) and the admin panel; both `tsc --noEmit` clean. FR / EN / AR key sets identical for everything added (`messages/*.json`, `resources/lang/*/returns_policy.php`).

## What was built
| Decision | Implementation |
|---|---|
| 48 h kept, counted from `seller_orders.delivery_confirmed_at` (fallback `updated_at`), only when v2 is on | `ReturnPolicy::deliveredAt/windowOpen`, `ComplaintController::eligibleQuery/returnableLines`; `closed_orders` explains a closed window |
| Cash refund at pick-up kept; amount frozen at approval | `complaints.refund_amount_locked`, set in `adminApprove` / failed-exchange→return / switch-to-refund; `payCashAtPickup` pays it; `applyFinance` never changes it (a drift is logged as an event) |
| 2–3 photos for every reason, seller accepts before pick-up | controller + service validation; existing accept step unchanged |
| Reasons decide what is allowed (exchange first) | `ReturnPolicy::optionsForLines`, server-side gate in `ReturnService::create`; the form only renders the API's `policy` block |
| Inspection controls stock / seller charges / disputes | `received` → `inspect`: Conforme = stock +1 on the exact variant, sale reversed, payout released. Non conforme = no stock, payout stays frozen, dispute opens, admin notified |
| Payout frozen from pick-up until inspection / dispute | `PayoutHold`; `SettlementController::create` skips frozen parcels; « En attente de retour » on admin finance (orders, pending payouts, order drawer, Returns tab) and seller earnings |
| Parcel pulled out of a draft batch at pick-up | draft recomputed, admin bell « Commande #X retirée du règlement brouillon : retour en cours », an emptied draft is deleted |
| Dispute decisions | charge seller / absorb / contact client / send back / treat as conforming (`ReturnService::decideDispute`), recorded in admin finance |
| Partial seller-fault charge | `SellerAdjustment::TYPE_RETURN_ITEM_FEE` (scenario 6: 9.000 + 4.280) |
| Category exclusions | `returns:flag-categories` (dry run first, `--apply` writes), tri-state flag on subcategories |
| Kill switch | `returns.policy.v2_enabled`, off at deploy; admin « Returns policy » button on the Complaints page |

### Last rounds
1. **Dispute "send back" is cash on delivery** for *refund already paid + delivery fee*: `ReturnService::sendBackCod`, stored in `complaints.sent_back_cod`. The admin modal shows the breakdown and an editable amount before booking (`cod_amount`, default the suggestion); the client's tracking and the French notification state the amount and why.
2. **Client refuses the sent-back parcel**: new admin action « Client refused the parcel » (`PATCH /admin/complaints/{id}/sent-back-refused`): the item is back at the seller, the dispute reopens (`dispute_status = open`, `sent_back_refused_at`), the admin is notified (`return_sent_back_refused`), the payout is untouched, the client no longer sees the send-back notice, and the admin decides again with any option. Refusing twice, or without a send-back, is a 422. Tests: `test_send_back_parcel_collects_…`, `test_client_refusing_the_sent_back_parcel_…`.
3. **Migration `2026_11_14_000003_add_send_back_cod_to_complaints`** (additive, reversible): `sent_back_cod`, `sent_back_refused_at`.
4. **Terms (FR/EN/AR)**: section 6 rewritten to the new policy (48 h from the real delivery date, exchange first, reasons, 2–3 photos, cash refund by the courier at pick-up with the amount fixed before, changed-mind return fee, exchange delivery at the client's expense for a size problem or a change of mind, excluded categories, shop inspection, send-back cash on delivery and refusal, "same item can't be returned twice"). Section 5 now says delivery is free everywhere and no fee is added to the displayed price; section 3 no longer says "delivery fees included". The returns policy page (`lib/legal/returnsPolicy.ts`) matches.
5. **Funnel diagnosis**: `FunnelDiagnosis::cartCause` no longer blames a delivery fee (sellers don't set one); the fallback suggestion points to price.
6. **Chatbot knowledge base** (`config/chatbot_kb.php`, `returns_complaints`, EN/FR/AR): same item can't be returned twice, `{complaint_hours}` (48) from the real delivery date, exchange first, 2–3 photos, reasons, cash refund by the courier at pick-up (amount fixed before), changed-mind return fee, exchange delivery paid by the client for size / change of mind, excluded categories, shop inspection and send-back. "One complaint per order" and the online-refund wording are gone. New test `test_returns_answer_matches_the_exchange_first_policy_in_the_three_languages`. Note: the bot describes the new policy, so enable `v2_enabled` soon after deploying (until then the form still asks for 1 photo and does not show the reason rules).

## Migrations to run (additive, nullable, reversible)
1. `2026_11_14_000001_add_returns_policy_v2_to_complaints`
2. `2026_11_14_000002_add_changed_mind_flag_to_categories` (schema only: flags nothing)
3. `2026_11_14_000003_add_send_back_cod_to_complaints`

## Deploy order
1. `php artisan migrate`
2. Deploy backend, storefront, admin panel (policy off: nothing changes for clients, sellers or the admin's flows).
3. `php artisan returns:flag-categories` (dry run, read the table), then `--apply` once approved. Slugs: whole categories `food-grocery`, `beauty-personal-care` (exception `hair-styling-tools`); subcategories `bras panties men-s-underwear nightdresses-robes socks tights swimwear burkinis feminine-care intimate-hygiene supplements vitamins sports-nutrition herbal-products diapers-wipes baby-food baby-bath-care dog-food cat-food personalized-gifts favors-boxes invitations-cards`. Slugs missing in production are reported and skipped.
4. Check the scheduler runs `returns:watch` (hourly, registered in `Kernel`).
5. Admin › Complaints › « Returns policy » › enable.

## Regression results per flow (section 1 of the brief)
1. Checkout, COD, prices, free delivery, multi-item discount, coupons, promos, flash, packs: Pricing (29), Delivery (49), Discounts / Promotions / Packs in the full run: green. Not modified.
2. Admin confirms → prepared → Intigo → webhook → delivered: Intigo / Orders folders green, not modified.
3. Refused parcel at the door: untouched code path, tests green.
4. Existing exchange flow, PDF slip, admin notification after seller acceptance: `ExchangeFlowTest`, `ReturnFlowTest`, `DeliveryDocumentsTest` green.
5. Bordereau upload / download: green, untouched.
6. Commission tiers, payouts, debt carry-over, settlement batches and proofs, finance pages: `SettlementPaymentProofTest`, `FreeDeliveryExamplesTest` (carry-over) green; `SettlementController::create` has one added `whereNotIn`.
7. Guest tracking and guest complaints: `GuestOrderAccessTest` green + the new guest v2 test.
8. Notifications: `BuyerNotificationsTest` and the full suite green; new events added, none changed.
9. Stock: `OrderStockTest` and the return restock tests green; v2 restocks only on Conforme (tested).
10. i18n: key parity checked for everything added.
Old complaint (filed before the policy) finishing with the old flow: `test_a_complaint_filed_before_the_policy_finishes_with_the_old_flow`.

## Things to know
- **The freeze is per seller order**, not per item (a batch links whole parcels): the other items of that parcel wait with it.
- Dispute resolutions do **not** reverse the sale on the order lines (only « treat as conforming » does): absorb / charge / send back leave the seller payout intact; "charge" is an explicit debit (`return_dispute_charge`). Nothing is clawed back from the client automatically. Units of a disputed return stay reserved (one attempt).
- Orders paid by card / D17 / wallet (switched off today) keep the old reception + admin refund flow, even for v2 complaints.
- The old one-step `receive` endpoint answers 422 for new (v2, COD) complaints; the seller dashboard shows « Colis retour reçu » / Inspect for them.
- The admin « To refund » counter now excludes v2 complaints (they wait for an inspection, not a refund).
- `choosetounsi-frontend/AGENTS.md` shows as untracked: it is written by `next dev` / `next build`, not by this work. Do not commit it with this change.
- Old seller-side "free delivery paid by you" texts are kept on purpose: they only show for orders placed before delivery became free. The old seller free-delivery option keys in `messages/*.json` (`seller.productForm.freeDelivery*`, `cartDrawer.shippingAtCheckout`, `checkout.confirm.deliveryFees`…) are no longer referenced by any code (not deleted).

## Bugs / loose ends outside this scope (not fixed)
- The known `PromotionPricingTest` related-products failure.
- Old docs `AUDIT_DISCOUNTS.md` / `PRICING_AUDIT.md` describe the old pricing.
- Return parcels have no Intigo status tracking (pick-up is marked by the admin or the courier app).
- The seller / admin complaint JSON returns raw columns (including image paths); only the client resource is an allow-list.
- `growth.php` still has a "delivery fee stops the order" text and `LeakingProductDetector` maps `shipping_cost`; the diagnosis no longer produces it, so it is dead text.
- Chatbot `place_order` answer still lists Wallet / D17 / bank card as payment choices although only COD is active (not touched).

## Final manual browser checklist
Set up: policy enabled, a delivered COD order (< 48 h), one product in `food-grocery` (or any flagged category) and one normal product with 2 sizes.
1. Footer « Échange et remboursement » opens the policy page in FR / EN / AR (AR is RTL). The product page shows « Échange facile sous 48h » (and the defect-only line for a flagged category), both link to the policy. The terms page (section 5 "Livraison gratuite partout en Tunisie…", section 6 new text) reads correctly in the three languages.
2. Complaint form: exchange is first and highlighted; « La taille ne me va pas » → refund greyed with the reason; sell the product out → refund offered.
3. Changed mind on a flagged category: disabled with the explanation; defective still works. Changed mind on a normal product: the « neuf, non porté, étiquettes » box is required and the form prints « Remboursement : 64,720 DT (69,000 − 4,280 frais de retour) » before submitting.
4. 1 or 4 photos are refused, 2–3 accepted for every reason; the seller sees the photos and accepts; admin approves: the refund figure is frozen in the drawer.
5. Pick-up marked: the drawer shows « payout frozen »; the parcel leaves a draft settlement (bell + totals recomputed, an emptied draft vanishes); « Create settlement » skips it; seller earnings and admin finance show « En attente de retour ».
6. Seller: « Colis retour reçu », then Inspect. Conforme: stock +1 on the right size, payout released, adjustments for a seller-fault return (9.000 + 4.280). Non conforme without photos is refused; with photos the admin sees the dispute. Try each decision.
7. **Send back**: the modal shows « refund already paid + delivery = suggested COD », the amount is editable; the client's tracking shows the photos, the reason and the cash amount. Then « Client refused the parcel »: the dispute reopens, bell for the admin, the client's send-back notice disappears; decide again (e.g. charge the seller).
8. Partial return of the T-shirt (seller fault): refund 30.000, extra −9.000 delivery-fee debit in seller finance; shoes changed-mind → 77.720.
9. Seller « Échange impossible » on an exchange: the seller can no longer accept; the client sees the banner and switches to a refund in one click (changed mind asks the unused box).
10. A shop that never clicks « reçu » for 3 days: admin bell + « Reception overdue » card; « Start the inspection » works.
11. Guest (tracking link): same form rules, 2–3 photos, cash at pick-up, no wallet anywhere.
12. Admin › Finance › Returns tab lists refund, payer, seller charges, payout hold and dispute decision per return.
13. Ask the chatbot « comment me faire rembourser ? » (FR/EN/AR): the answer follows the new policy (48 h, same item once, exchange first, 2–3 photos, cash at pick-up, return fee, excluded categories).
14. Switch the policy off: a new request needs 1 photo again and the 48 h count from the order update; an open complaint keeps going.
15. Old open complaint (filed before enabling): cash at pick-up reverses the sale immediately and `receive` works as before.
