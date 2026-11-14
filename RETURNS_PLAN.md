# Exchange-first returns & refunds: implementation plan (rev. 2)

Date: 2026-11-14. **Nothing is implemented.** Rev. 2 applies your answers. Based on `RETURNS_AUDIT.md` (F1, F2, F4 and F9 there are superseded by your decisions: see §0). Waiting for your OK.

## 0. Your decisions, and what they replace

| # | Decision | Effect on the plan |
|---|---|---|
| 1 | Window stays **48 h**, counted from `seller_orders.delivery_confirmed_at` (fallback `updated_at` when null). "48h" texts stay everywhere. Promise text becomes « Échange facile sous 48h. Remboursement si le produit est défectueux, non conforme ou si l'échange est impossible. » (+EN/AR). | No working-day logic, no window setting. `COMPLAINT_WINDOW_HOURS` stays; only its *start date* changes. |
| 2 | **Current refund flow is kept**: the courier pays the client in cash at pick-up. No wallet refund, no D17/transfer, no partial wallet payment at checkout. Refund amount computed and **fixed before the pick-up is booked**; that exact amount is what the courier pays. Photos: **2 to 3 for every reason**, seller accepts them (existing accept step) before pick-up. | Removed from the plan: wallet/D17/RIB, payout status, refund proofs, `ReturnRefundProof`, guest wallet invitation, wallet at checkout (§7 of rev. 1), the `inspection_conform` / `sent_back` statuses. |
| 3 | Inspection stays but **no longer controls the refund**: it controls stock (+1 only if Conforme), seller charges and disputes. Non conforme after a refund: admin notified, **decides case by case** (charge the seller / absorb / contact the client / send the item back), decision recorded in admin finance. Clock starts when the seller clicks **« Colis retour reçu »**; not clicked 3 days after pick-up ⇒ admin notified, who can start it with a button. | No new money flow. Stock/seller charge logic hangs off the inspection result. |
| 4 | 4.280 = the after-sale return fee (admin setting, per size). | `afterSaleReturnFee` as is. |
| 5 | Show the exact categories matched before applying. | §4 (checked against your local database). Nothing applied: flags are set by a separate command only after your OK. |
| 6 | Non-conforming item sent back to the client only if the admin decides: **admin books the parcel by hand, client pays it in cash.** | Decision option `send_back` with a manual Intigo nid. |
| 7 | `v2_enabled` kill switch, off at deploy. | §1. |

## 1. Principles

1. **Extend, never replace.** `ReturnService` stays the one place a return moves. New behaviour is guarded by `complaints.policy_version = 2`; `NULL` runs exactly today's code (tested).
2. **Kill switch.** Platform setting `returns.policy` (JSON in `platform_settings`, no migration): `{ "v2_enabled": false, "receive_timeout_days": 3, "inspection_days": 3 }`. Off at deploy: everything behaves as today (including the window start date, see Q1). On: new complaints are stamped v2. Complaints already stamped v2 keep v2 if it is switched off again.
3. **One refund calculator.** Amounts come from `previewRefund / share / deliveryRefundPreview / refundFor / applyFinance` (R7). Change to money logic: only the partial seller-fault charge (§3.3). The backend decides which options are allowed and why; frontends only render the API's `policy` block.
4. **Additive, reversible migrations.** No status, column, route or API field renamed or removed.

## 2. Statuses: **no new status**
Statuses stay exactly as today (`requested … picked_up → returned_to_seller → refunded`, exchange path unchanged). The new information is columns + timeline events displayed on the existing steps: « Reçu par le vendeur » = `returned_to_seller`; « Conforme / Non conforme » = `inspection_result`, shown next to it; « Litige » (admin decision pending/recorded) = `dispute_*`. Every existing status keeps its label. Client-side promise: seen in the tracking as today.

## 3. Changes

### 3.1 Migrations (additive, nullable/defaulted, working `down()` that drops exactly these columns)
1. `2026_11_14_000001_add_returns_policy_v2_to_complaints`
   - `policy_version` TINYINT NULL (index).
   - Exchange impossible: `exchange_impossible_at`, `exchange_impossible_by`, `exchange_impossible_reason` (500), `switched_to_refund_at`.
   - Changed-mind declaration: `item_unused_confirmed` BOOLEAN NULL.
   - Frozen amount: `refund_amount_locked` DECIMAL(10,3) NULL, `refund_locked_at` (the figure the courier pays).
   - Receipt / inspection: `receive_timeout_notified_at`, `inspection_due_at`, `inspection_result` VARCHAR(12) NULL (`conform|nonconform`), `inspection_by`, `inspection_at`, `inspection_comment` TEXT, `inspection_photos` JSON, `inspection_reminder_sent_at`, `inspection_timeout_notified_at`.
   - Dispute (non conforme): `dispute_status` VARCHAR(12) NULL (`open|resolved`), `dispute_decision` VARCHAR(16) NULL (`charge_seller|absorb|contact_client|send_back`), `dispute_amount` DECIMAL(10,3) NULL, `dispute_note` TEXT, `dispute_decided_by`, `dispute_decided_at`, `sent_back_nid` VARCHAR(60) NULL, `sent_back_fee` DECIMAL(8,3) NULL.
2. `2026_11_14_000002_add_changed_mind_flag_to_categories` — `categories.no_changed_mind_return` BOOLEAN default 0; `subcategories.no_changed_mind_return` BOOLEAN **NULL** (NULL = inherit, `0` = explicit exception). **Schema only: no data flagged by the migration.**
   Data: artisan command `returns:flag-categories` (dry run by default, prints exactly what it will flag; `--apply` writes; matching by slug, missing slugs reported and skipped). Run only after you approve §4.
3. `complaint_costs.kind` / `seller_adjustments.type`: column types checked when implementing; new values are plain strings unless a column is an ENUM (then a tiny reversible migration).

### 3.2 Backend: new files
| File | Role |
|---|---|
| `app/Services/Returns/ReturnPolicy.php` | settings (`enabled()`, timeouts), `deliveredAt(SellerOrder)`, `windowOpen()` (48 h from `delivery_confirmed_at`), `categoryAllowsChangedMind(Product)`, `exchangeImpossible(line)`, `optionsFor(line)` → per reason `{exchange_allowed, refund_allowed, photos_min:2, photos_max:3, shipping_payer, disabled_reason}` (translated), `returnsMode(Product)` |
| `app/Services/Returns/ReturnInspection.php` | `received()` (seller click or admin start), `inspect()` (conform / nonconform + photos + comment), `openDispute()`, `decideDispute()`, timeout watcher; all through `ReturnService::move()`/`event()` |
| `app/Console/Commands/ReturnsWatch.php` (+schedule) | (a) pick-up done, no « reçu » 3 days later ⇒ admin notified once; (b) received, not inspected after `inspection_days` ⇒ seller reminder + admin notified once; idempotent via stamps |
| `app/Console/Commands/ReturnsFlagCategories.php` | the dry-run/apply category seeding above |
| `tests/Feature/Returns/*` | §6 |

### 3.3 Backend: modified files (extend only)
| File | Change | Callers re-checked |
|---|---|---|
| `Models/Complaint.php` | `policy_version` helper `isV2()`, fillable/casts for new columns; statuses untouched | controllers, presenter, resources |
| `Services/Returns/ReturnService.php` | **create():** v2 stamp; reason × resolution × category validation; **2-3 photos for every reason** (old: ≥1, unchanged for v1); `item_unused_confirmed`; keep `shipping_payer` rules. **adminApprove():** v2 freezes `refund_amount_locked` (from `refundFor`) before the pick-up task exists. **payCashAtPickup/applyFinance:** v2 pays and records the **locked** amount (a recompute that differs is logged as an event and never changes what was paid). `isCashRefund()/cashToPay()` unchanged. **New:** `sellerMarkExchangeImpossible()`, `clientSwitchToRefund()` (re-freezes via the same functions), `received` + `inspect` (v2: `receive` no longer decides restock alone: Conforme = resaleable ⇒ restock +1; Non conforme = damaged ⇒ no restock + admin notified; the move to `refunded` still happens once `refunded_at` is set, as today). **`applyFinance`:** v2 + seller fault + **partial** ⇒ extra `SellerAdjustment::TYPE_RETURN_ITEM_FEE` = Σ `included_fee × qty` of returned lines (pack lines: their `included_fee_total` share), idempotent; full returns and changed mind untouched. | `ExchangeSender`, `ExchangeStatusSync`, 4 controllers, `RefundDeliveryController`, `SyncStatuses` |
| `Services/Returns/ReturnPresenter.php` | inspection/dispute info for staff; client sees inspection photos + reason only when the admin decided `send_back`; internal events extended | client/guest/staff |
| `Api/Client/ComplaintController.php` (guest inherits) | `eligible-orders`: all existing fields kept, + `policy` (flag, `window_ends_at`, per-line `options`, `closed_reason`); window start = `delivery_confirmed_at` per parcel when v2 on; `preview` accepts `resolution_type`, returns `deductions[]` + the exact refund from `previewRefund`; `store`: validation above; new `PATCH {id}/switch-to-refund` | storefront, `GuestReturns` |
| `Api/Seller/SellerComplaintController.php` | `PATCH {id}/exchange-impossible`, `PATCH {id}/received` (« Colis retour reçu »), `PATCH {id}/inspect` (multipart photos); stats; old `approve/reject/receive` unchanged | seller dashboard |
| `Admin/AdminComplaintController.php` | `PATCH {id}/start-inspection` (button when the seller didn't click), `PATCH {id}/dispute` (decision, amount, note, optional nid for `send_back`), payload/stat additions (frozen refund, method, who paid shipping, seller charges, dispute) | admin page |
| `Api/Delivery/RefundDeliveryController.php` | `cash_to_pay` already follows `cashToPay()`; v2 reads the locked amount | courier app |
| `Http/Resources/Buyer/BuyerComplaintResource.php` | additive: `policy_version`, `exchange_impossible`, `inspection` block (only when allowed) | client + guest |
| `Models/SellerAdjustment.php` | two new constants: `TYPE_RETURN_ITEM_FEE`, `TYPE_RETURN_DISPUTE` (admin "charge the seller") | `SettlementController`, `EarningsController`, `Settlements` |
| `Admin/FinanceController.php`, `Api/Seller/EarningsController.php` | read-only additions: dispute decisions and the new adjustment labels | finance pages |
| `Notifications/Buyer/ComplaintNotification.php`, `Returns/*Notification.php`, `resources/lang/{fr,en,ar}/*` | events: client `exchange_impossible`, `sent_back`; seller `inspection_reminder`; admin `inspection_nonconform`, `receive_timeout`, `inspection_timeout`; (existing events untouched) | `BuyerNotifier`, tests |
| `Api/ProductController.php` (public payload) | + `returns_mode` (`exchange_easy` / `defect_only`) | product page |
| `Admin/AdminCategoryController.php`, `AdminSubcategoryController.php` | read/write `no_changed_mind_return` | admin categories |
| Admin settings endpoint | GET/PUT `returns.policy` | admin panel |
| `PlatformFacts`, `ShopOverview`, `config/chatbot_kb.php`, `OrderStatusNotification`, `lib/legal/terms.ts` | **48h wording kept**; promise sentence updated; no number change | e-mails, shop, chatbot |
| `routes/api.php`, schedule | routes above (guest mirrors only `eligible/preview/store/switch-to-refund`) | |

**Not touched:** checkout, `OrderPricing`, `PricingService`, `WalletService`, Intigo send/webhook/refused-parcel code, `SettlementController`, `SettlementProof`, bordereau, stock services.

### 3.4 Inspection clock
Pick-up done (`picked_up_at`) → seller clicks « Colis retour reçu » (`received_at`, sets `inspection_due_at = received_at + inspection_days`) → seller inspects. If no click `receive_timeout_days` (3) after pick-up: admin notified once and gets a **« Démarrer l'inspection »** button (same effect as the seller's click, `role = admin`). Admin may also inspect on the seller's behalf. Return-parcel tracking by Intigo remains a listed gap (not fixed).

### 3.5 Non conforme (refund already paid at pick-up)
Seller marks Non conforme with ≥1 photo + comment ⇒ no restock, admin notified, `dispute_status = open`. Admin decides in the drawer: **charge the seller** (amount entered, default = refunded amount; creates `TYPE_RETURN_DISPUTE`, carried over by the existing debt logic), **absorb**, **contact the client** (note, no money), or **send back** (admin books the Intigo parcel by hand, pastes the nid; `sent_back_fee` = frozen return fee, paid by the client in cash on that parcel; booked in `complaint_costs`). The decision, amount and who decided are shown in admin finance. Exchange: same dispute for a non-conforming old item. Nothing is clawed back from the client automatically.

### 3.6 Frontends
**Storefront:** `ComplaintRequestForm` driven by the API `policy` block: reason first, exchange highlighted, refund second when allowed, disabled options with the server's reason, **2-3 photos for every reason** (`ProofPhotosInput` min 2 / max 3), "unused + tags" checkbox for changed mind, **exact deduction from the preview endpoint before submit**, link to the policy page. `ReturnTracking`/`ComplaintCard`/`types/complaint.ts`: exchange-impossible banner + "Switch to refund" button, `closed_reason` after 48 h, send-back photos/reason. `GuestReturns` and guest tracking likewise. Product page line under the free-delivery badge (« Échange facile sous 48h » / « Échange/remboursement uniquement si défectueux ou non conforme »). New page `app/returns-policy/page.tsx` (FR/EN/AR), footer link, `terms.ts`. `messages/*.json` identical key sets, RTL checked.
**Seller dashboard:** « Échange impossible » (reason), « Colis retour reçu », « Inspecter » (Conforme / Non conforme with photos + comment), reminders, counters.
**Admin panel:** `ReturnDrawer`: frozen refund amount, « Démarrer l'inspection », dispute block (photos, decision, amount, note, nid), seller charges; settings drawer on the Complaints page (kill switch, two timeouts); categories toggle « Retour pour changement d'avis non autorisé »; `FinancePage`: per-complaint refund amount, method (cash at pick-up), who paid shipping, seller charges, dispute decision.

## 4. P4 list: exact matches, checked against your local database (read-only)
Nothing is applied. Local DB: 22 categories, 364 subcategories. Matching is by slug; the command skips and reports slugs that don't exist (your production catalogue may differ from local).

**Whole category flagged (all its subcategories inherit):**
- `food-grocery` (id 4, 21 subcategories): olive-oil, honey, dates, harissa, spices, tea, coffee, canned-goods, organic-products, bsissa-traditional-products, tunisian-pastries, pasta-couscous-grains, jams-spreads, nuts-dried-fruits, chocolate-sweets, biscuits-snacks, dairy-cheese, coffee-tea, juices-syrups, water-soft-drinks, gluten-free.
- `beauty-personal-care` (id 5, 25 subcategories; note the local slug is `beauty-personal-care`, not `beauty-care` as in the CSV): moisturiser, serum, face-mask, lipstick, foundation, mascara, eau-de-parfum, shampoo, hair-mask, argan-oil, face-makeup, eye-makeup, nails, makeup-tools, skincare, body-care, hair-care, hair-color, natural-beauty-henna-tfal-argan, hammam-bath, deodorants, shaving-beard, hair-removal, gift-sets, **hair-styling-tools = explicit exception, stays allowed** (appliances).

**Single subcategories flagged:**
| Group | Category › subcategory (slug) |
|---|---|
| Underwear / lingerie | fashion-clothing › `bras` Soutiens-gorge, `panties` Culottes, `men-s-underwear`, `nightdresses-robes` Nuisettes & Peignoirs, `socks`, `tights` |
| Swimwear | fashion-clothing › `swimwear`, `burkinis` |
| Intimate hygiene | health-wellness › `feminine-care`, `intimate-hygiene`; kids-baby › `diapers-wipes` |
| Ingestible | health-wellness › `supplements`, `vitamins`, `sports-nutrition`, `herbal-products`; kids-baby › `baby-food`; pets › `dog-food`, `cat-food` |
| Personalized | weddings-events › `personalized-gifts`, `favors-boxes`, `invitations-cards` |

**Found in local DB but NOT proposed (tell me if you want them):** fashion-clothing › `pyjama-set`; sports-outdoors › `swimming-gear`; kids-baby › `baby-bath-care`; automotive › `car-perfume` (a car air freshener, not cosmetics). CSV slugs `pyjamas`, `swimming` do not exist locally. There is no "lingerie" slug: underwear = the six fashion subcategories above.

## 5. Scenarios → how the code produces them (S fee 9.000, return fee 4.280, display 69.000)
1. **Wrong size → exchange** (`doesnt_fit`): exchange only; client pays the exchange fee (existing); seller keeps payout; new variant −1 at approval (existing reservation), old variant +1 only at `inspect = conform`.
2. **Defective, no stock → refund**: exchange impossible (no valid replacement) ⇒ refund offered; 2-3 photos; payer = seller; refund 69.000 **frozen at approval**, paid in cash by the courier at pick-up; `applyFinance`: payout + commission reversed, parcel fee 9.000 + return fee 4.280 charged to the seller (existing full-return logic).
3. **Changed mind**: preview shows `64,720 DT (69,000 − 4,280)`; frozen 64.720 paid at pick-up; seller payout/commission reversed, nothing else charged.
4. **Underwear**: changed mind disabled with explanation (UI + server-side 422); defective / wrong item allowed.
5. **Abuse**: changed mind (refund paid at pick-up) → seller marks Non conforme with photos → no restock → admin dispute: decision recorded (charge seller / absorb / contact / send back); the client sees photos and reason only for `send_back`; seller payout of the order is reversed as in any refund (the item came back), so "seller keeps the payout" now holds only if the admin chooses nothing to recover... see Q9.
6. **Partial, seller fault**: refund 30.000 (R7, frozen); new `TYPE_RETURN_ITEM_FEE` −9.000 + `TYPE_RETURN_SHIPPING` −4.280; T-shirt payout/commission reversed.
7. **Partial, changed mind, shoes**: 82.000 − 4.280 = 77.720 frozen; shoes payout/commission reversed; nothing else charged.

## 6. Tests (`tests/Feature/Returns/`)
Baseline first (see §8 and the result below), regression tests for uncovered section-1 flows (settlement payment proofs, backend lang EN/AR parity) written and passing on current code before any change.
New: scenarios 1-7; 48 h window from `delivery_confirmed_at` (not `updated_at`; fallback; expired ⇒ explicit message; kill switch off ⇒ unchanged behaviour); 2-3 photos for every reason (1 or 4 rejected); seller accepts before pick-up; refund frozen at approval equals what the courier's `cash_to_pay` and the recorded refund show, even if prices/promos change afterwards; guest return with cash refund and guest tracking; manual « échange impossible » → client switch to refund → re-frozen amount; underwear changed-mind refused; « Colis retour reçu » + inspection conform (+1 stock on the exact variant, once) / nonconform (no stock, admin notified, dispute open, decision recorded each of the 4 ways, seller debt through carry-over when the balance is too low); `receive_timeout` (admin notified once, button starts it) and `inspection_timeout`; **old complaint (policy_version NULL) finishes with the old flow**; all section-1 regressions (checkout totals, refused parcel path, Intigo flow, bordereau, settlements/proofs, notifications, stock, i18n parity).
End: full suite alone (only the known failure), `next build` for both frontends, never during a test run.

## 7. Risks
- **Window start change** (`delivery_confirmed_at`) alters who is eligible; gated by `v2_enabled` (Q1).
- **Frozen amount vs `applyFinance` recompute**: v2 pays the frozen figure and logs any difference; test covers a price change between approval and pick-up.
- **Seller-fault partial charge** is new money for sellers: seller notification wording + FR/EN/AR adjustment labels.
- **Deploy order**: backend → frontends → flip `v2_enabled`. Old frontends keep working (additive).
- Out-of-scope issues (listed, not fixed): known `PromotionPricingTest` failure; stale `AUDIT_DISCOUNTS.md` / `PRICING_AUDIT.md`; no Intigo tracking of return parcels; `eligible-orders` uses `orders.updated_at` for v1.

## 8. Remaining questions: default I will use
1. **Window start date** (`delivery_confirmed_at` instead of `orders.updated_at`): apply **only when v2 is on** (deploy stays inert), or also fix it for the old policy immediately? **Default: only with v2.**
2. **Exchange impossible** = no valid replacement offered by the form (zero stock, or only other-price variants), not only "zero stock on all variants". **Default: no valid replacement.**
3. **Reason mapping** (`doesnt_fit`, `damaged_product`, `wrong_product`, `changed_mind`; old `wrong_size`/`wrong_color` stay for old complaints). **Default: yes.**
4. **Wrong size + impossible exchange**: 100% refund, seller charged like seller fault. Changed mind + impossible exchange: refund only if the category allows it, return fee deducted. **Default: yes.**
5. **One attempt**: after a dispute with `send_back`, the units cannot be returned again. **Default: yes.**
6. **Photos** stay on the public `complaints/` disk like today (inspection photos too). **Default: yes.** (Private disk would need an authenticated route for the client view.)
7. **Settings location**: drawer on the Complaints admin page. **Default: yes.**
8. **« Charge the seller » amount**: free amount entered by the admin, default = refunded amount. **Default: yes.**
9. **Scenario 5 ("seller keeps the payout")**: with cash already paid at pick-up, the sale is reversed as in any refund, so the seller loses the payout unless the admin recovers nothing. Options: **(a) default: v2 reverses the seller payout only when the inspection is Conforme; on Non conforme the seller payout stays and the dispute decides (absorb = platform pays; charge seller = debit; send back = no reversal).** (b) always reverse, then decide. Default (a) changes when `applyFinance` runs for v2 (at inspection, not at pick-up). Please confirm (a): it is the one place where the order of operations differs from today.
10. **Deploy steps**: run migrations, deploy, `returns:flag-categories` dry-run → apply, flip `v2_enabled`. **Default: yes.**
