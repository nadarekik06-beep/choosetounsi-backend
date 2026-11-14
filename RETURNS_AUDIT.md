# Returns & refunds: audit of the current system

Date: 2026-11-14. Read-only audit, nothing was changed. Sources: `PRICING_REPORT.md`, `PRICING_PLAN.md` and the code at `main` (`4e84901`).

## 0. Findings that change the plan (read these first)

| # | Finding | Why it matters |
|---|---|---|
| F1 | **COD refunds are paid in cash by the courier at pick-up, before the shop inspects the item** (`ReturnService::markPickedUp` → `payCashAtPickup` → `applyFinance`; the delivery app shows `cash_to_pay`). The sale is reversed at pick-up, restock happens later. | Directly contradicts P5 ("never refund before inspection"). The new policy must switch this off for new complaints only. Old open complaints must keep it. |
| F2 | **The return window is 48 h and counted from `orders.updated_at`**, not from the delivered date (`Complaint::COMPLAINT_WINDOW_HOURS`, `ComplaintController::eligibleQuery`). Any later write to the order (return sync, status change) moves the window. | P1 needs 10 working days from the real delivered date. `seller_orders.delivery_confirmed_at` is set by `ParcelStatus` when the Intigo webhook moves a parcel to `delivered` (to be proven by a test). It is per parcel, so the window must be per seller_order. |
| F3 | **The constant `COMPLAINT_WINDOW_HOURS` is used in 6 places** besides the controller: `OrderStatusNotification` (delivered e-mail text), `PlatformFacts`, `ShopOverview` (`complaint_window_hours`, used by the shop "Closing" section), chatbot KB, terms page text, `SupportChatWidget` comment. | All wording about "48 h" must move to the new policy or clients get contradictory promises. |
| F4 | **The wallet cannot be spent at checkout today.** `CheckoutPaymentMethods::DEFAULTS['wallet'] = false` (only COD is on). The code path exists but is *full payment only*: `deductForOrder` takes `total_amount`, `walletShort()` rejects if the balance is lower. No partial payment, no Intigo COD reduction. | P6 says to describe, then ask before implementing. See PLAN §7 and question Q1. |
| F5 | **Existing reason codes do not match the policy table.** `wrong_size` and `wrong_color` mean "the seller *sent* the wrong size/color" and are seller fault (pays the parcel). The policy's "Wrong size / doesn't fit" is a client-side reason. `doesnt_fit` and `prefer_other_color` already exist and are client fault. | The new form must emit only `doesnt_fit`, `damaged_product`, `wrong_product`, `changed_mind` (no new `complaint_type` values, no migration of old ones). Old values stay valid for old complaints. |
| F6 | **"Intigo return fee" is ambiguous.** Two fees exist per size in `DeliverySettings`: `afterSaleReturnFee` (what the client pays on a return, frozen on the complaint as `return_shipping_fee`, shown in the form) and `refusedFee` (`return_fee`, refused parcel at the door, never shown to buyers). Scenario figures (4.280) are taken as the **after-sale return fee, size S**. | Q2. |
| F7 | **Seller charges today.** Any seller-fault return: `return_shipping_fee` debited (`SellerAdjustment::TYPE_RETURN_SHIPPING`) in `applyFinance`. Parcel fee (`TYPE_RETURN_PARCEL_FEE`) only on a **full** return (`refundIncludedDelivery`, `units === 0`). Partial seller-fault returns pay only the return fee. | P6's one change: add the returned lines' included fee on partial seller-fault returns (scenario 6). |
| F8 | **Return parcels are not tracked automatically.** Exchange parcels sync from Intigo (`ExchangeStatusSync`). Return pick-ups do not: the admin pastes `intigo_return_ref` and clicks "picked up". The delivery-app courier flow calls `deliveredToSeller`. Nothing records "delivered back to the seller" for an Intigo return. | The 3-day inspection clock (P5.3) needs a start timestamp. See PLAN §3.4 and question Q5. |
| F9 | `complaints.refund_status` is the **mirror of the courier refund task** (pending/assigned/picked_up/completed), not a money status. | The new "remboursement à effectuer / effectué" state needs its own column (`payout_status`). Do not reuse `refund_status`. |
| F10 | Guest orders: `refundMethodsFor()` already removes `wallet` for orders without `user_id`. COD orders map to `['cash']` only. Guest complaints reuse the client controller via `GuestComplaintController extends ComplaintController` (X-Order-Token). | Every change to `ComplaintController` automatically applies to guests. Good (one place), but a bug there hits both. |
| F11 | MySQL is **not running** on this machine right now (connection refused), and the suite runs against MySQL `choosetounsi_test` (`phpunit.xml`). | The baseline run (section 3.2 of your brief) needs XAMPP MySQL started first. No php/artisan/node process is running. |

## 1. The complaint flow today

### 1.1 Data model (backend)
- `complaints` (+ `complaint_items`, `complaint_events` timeline/audit, `complaint_costs` Intigo billing, `refund_delivery_tasks`, `seller_adjustments`, `wallet_transactions`).
- Key columns: `status` (string), `resolution_type` (`return_refund` | `exchange`), `complaint_type` (8 codes, `Complaint::COMPLAINT_TYPES`), `shipping_payer` (`seller` | `client`, from `payerFor()`), `return_scope` (`full`|`partial`), `items_amount`, `refund_amount`, `return_shipping_fee`, `exchange_fee`, `fee_size`, `fee_exceeds_refund`, `refund_method/reference`, `refunded_at/by`, `finance_applied_at`, exchange columns (`intigo_exchange_nid`, `exchange_sync_status`…), `image_paths` (public disk `complaints/`).
- `complaint_items`: per order line the money as paid (`net_amount`, `commission_amount`, `seller_amount`), `condition` (`resaleable|damaged`), `restocked_at`, replacement variant + reservation stamps.
- Old exchange rows without `fee_size` are "legacy exchanges" (closable only).

### 1.2 Statuses (`Complaint::TRANSITIONS`)
```
requested → seller_accepted | seller_rejected(→ escalated) | admin_approved | rejected | cancelled
seller_accepted / escalated → admin_approved | rejected
return:   admin_approved → pickup_scheduled → picked_up → returned_to_seller → refunded
exchange: admin_approved → exchange_in_transit → exchanged → exchange_completed
          exchange_in_transit → exchange_failed → (retry | switch to return | cancel)
terminal: rejected, cancelled, closed (legacy), refunded, exchange_completed
```
Labels: `resources/lang/{fr,en,ar}/statuses.php` (`statuses.return.*`), timeline path in `ReturnPresenter::PATH / EXCHANGE_PATH`; storefront `types/complaint.ts`, `ReturnTracking.tsx`; admin `ReturnDrawer.tsx` + `complaints/page.tsx`; seller `app/seller/complaints/page.tsx`.

### 1.3 Who does what (`ReturnService` is the only place a return moves)
1. **Client** (`ComplaintController`, guest: `GuestComplaintController`): `eligible-orders`, `preview` (refund preview), `store` (multipart, ≥1 photo **always required**, `description ≥ 20`), `escalate`, `cancel`. One complaint per seller_order group, quantity per line, `reservedQuantities()` blocks double returns.
2. **Seller** (`SellerComplaintController`): `approve`, `reject` (reason), `note`, `receive` (condition per item `resaleable|damaged` → restock).
3. **Admin** (`AdminComplaintController`): `approve` (can switch payer, books the Intigo exchange), `reject`, `confirm-rejection`, `override-approve`, `schedule-pickup`, `picked-up`, `receive`, `intigo-return-ref`, `refund` (wallet/d17/bank/card), `cancel`, `close`, `return-slip` (PDF), exchange `book/nid/delivered/failed/decide`.
4. **Courier app** (`Api/Delivery/RefundDeliveryController`, `CreateRefundDeliveryTask` listener on `ComplaintApproved`, `RefundTaskSync`): pick-up task, `cash_to_pay`.
5. **Notifications** after commit: client in-app + e-mail via `BuyerNotifier`/`ComplaintNotification` (guest: e-mail only if `guest_email`, otherwise the tracking page); seller `ReturnSellerNotification`, `ComplaintCreatedNotification`; admin `ReturnAdminNotification`. All keys in `resources/lang/*/buyer_notifications.php`, `notifications.php`.

### 1.4 Money and stock today
- **Refund amount** (`ReturnService`): `share()` = proportional line money in integer millimes; `deliveryRefundPreview()` → `PricingService::recomputeParcel($soId, $minusUnits)` = the pricing task's R7 recompute (parcel recomputed "as if the units had never been ordered", fee frozen in `seller_orders.fee_rates`); `refundFor()` = items − `clientFee()` (client-paid return fee), never below 0, `fee_exceeds_refund` flag. `previewRefund()` is the server-side preview behind `POST /client/complaints/preview` (guest: `/guest/complaints/preview`).
- **Finance reversal** (`applyFinance`, once, `finance_applied_at`): order lines reduced, `reverseSellerOrder()` moves seller payout/commission by the base share, `refundIncludedDelivery()` recomputes the parcel and (full + seller fault) debits the parcel fee, `syncOrder()`, return fee debit when `shipping_payer = seller`, `bookCost()` in `complaint_costs`. Paid-out sale → `TYPE_RETURN_DEBIT` adjustment. Draft settlement batches are recomputed.
- **Seller debt carry-over**: `SettlementController::create → carryOverDebt()`; a payout never goes below 0, uncovered debits stay pending and come off later payouts.
- **Wallet**: `users.wallet_balance`, `WalletService::creditReturnRefund()` (reason `return_refund`) used by admin `refund(method=wallet)`. Client reads balance/transactions in `PaymentController`.
- **Stock**: restock only on `receive` when the item is `resaleable`, per line, once (`restocked_at`), on the exact variant. Exchange: replacement reserved at admin approval (`reserveReplacements`), released on cancel/switch; old item restocked at `receiveOldItem`.
- **Refused parcels at the door**: a separate path (`ParcelStatus` / `IntigoStatusSync` / `RefundTaskSync`, `SellerAdjustment::TYPE_REFUSED_PARCEL`). Not touched by this task.

### 1.5 Settlement-proof storage pattern (to reuse)
`App\Support\SettlementProof`: private disk (`storage/app/private/settlement-proofs`), random 40-char names, real MIME detection (jpg/png/webp/pdf, 5 MB), columns `proof_path/original_name/mime/size/uploaded_by/uploaded_at`, served only by authenticated endpoints (admin, or the owning seller) via `SettlementController::showProof/storeProof/destroyProof`, seller route in `Admin\…`/`EarningsController`. `SettlementProof` itself is shared with live finance code, so it will be **copied as a sibling class** (`ReturnRefundProof`, own directory), not edited.

### 1.6 Frontends
- **Storefront** (`choosetounsi-frontend`): `app/components/returns/{ComplaintRequestForm,ReturnItemsPicker,ProofPhotosInput,ReturnTracking,ComplaintCard}.tsx`, `app/complaints/{page,new}`, `app/profile/_components/ComplaintsTab.tsx`, `components/guest/GuestReturns.tsx`, `lib/complaintApi.ts`, `types/complaint.ts`, `app/orders/page.tsx` (return button), `app/order/track/[token]` (guest tracking). Legal texts `lib/legal/terms.ts`; footer `components/layout/SiteFooter.tsx`; product page `app/products/[slug]/page.tsx` + `FreeDeliveryBadge.tsx`. Messages: `messages/{fr,en,ar}.json`.
- **Seller dashboard**: `app/seller/complaints/page.tsx` (1355 lines: list, drawer, accept/refuse/receive).
- **Admin panel**: `app/(dashboard)/complaints/{page,ReturnDrawer}.tsx`, `finance/FinancePage.tsx` (refunded totals, after-sale costs), `delivery-fees/page.tsx` (after-sale fees per size), `lib/complaintApi.ts`.

## 2. Catalogue (source of the P4 list)
No DB reachable now, so the catalogue was read from `database/seeders/data/choosetounsi_categories.csv` (17 categories, ~300 subcategories, slugs stable). The seeding migration will match on **slug** and skip anything missing, so it is safe on production. Proposed list in the plan §4.

## 3. Existing tests relevant to this task
`Orders/ReturnFlowTest`, `Orders/ExchangeFlowTest`, `Orders/GuestOrderAccessTest`, `Pricing/FreeDeliveryExamplesTest` (partial/full return, seller debt carry-over), `Pricing/FreeDeliveryRulesTest` (return preview), `Notifications/BuyerNotificationsTest`, `Intigo/IntigoAdminAndCancelTest`, `Orders/AdminConfirmationRequiredTest`, `Delivery/ParcelWorkflowTest`/`ParcelLifecycleTest`, `Buyer/BuyerOrderPrivacyTest`, `Orders/DeliveryDocumentsTest` (slip). Gaps for section 1 flows (no dedicated regression test found by name): settlement payment proofs, bordereau (exists: `BordereauDownloadTest`, `ManualBordereauTest`), WhatsApp reminders (exists), EN/AR key parity for backend lang files. These get regression tests *before* any change.

## 4. Impact map: files that will be modified, callers, section-1 flows touched

| File | Callers / dependents | Section 1 flows that depend on it |
|---|---|---|
| `app/Models/Complaint.php` (new constants/statuses/helpers; existing ones untouched) | `ReturnService`, all 4 complaint controllers, `ReturnPresenter`, `BuyerComplaintResource`, notifications, `RefundTaskSync`, `RefundDeliveryController`, `SellerRevenueService`, `FinanceController`, `AiChatController`/`ActionFactory` | 4, 6, 7, 8 |
| `app/Services/Returns/ReturnService.php` | the 4 controllers, `ExchangeSender`, `ExchangeStatusSync`, `RefundDeliveryController`, `Console\Intigo\SyncStatuses` | 3 (no: separate path), 4, 6, 9 |
| `app/Services/Returns/ReturnPresenter.php` | client/guest/staff controllers, `BuyerComplaintResource` | 4, 7 |
| `Api/Client/ComplaintController.php` (+ `GuestComplaintController` inherits) | storefront returns form, guest returns | 7 |
| `Api/Seller/SellerComplaintController.php` | seller dashboard | 4, 9 |
| `Admin/AdminComplaintController.php` | admin complaints page | 4, 6 |
| `Api/Delivery/RefundDeliveryController.php` (cash_to_pay must be 0 for v2) | courier app | 4 |
| `Http/Resources/Buyer/BuyerComplaintResource.php`, `BuyerOrderResource.php`, `OrderTrackingController.php` | "Mes commandes", guest tracking | 1, 7 |
| `Admin/FinanceController.php`, `Api/Seller/EarningsController.php` (read-only additions: complaint finance columns) | admin & seller finance pages | 6 |
| `Admin/SettlementController.php` | **not modified** (carry-over reused as is) | 6 |
| `Models/SellerAdjustment.php` (one new TYPE constant) | `SettlementController`, `EarningsController`, `Settlements::recomputeDraft` | 6 |
| `Services/PricingService`, `OrderPricing`, `CheckoutController`, `WalletService` (existing methods) | checkout | 1: **not modified** unless Q1 is approved |
| `OrderStatusNotification`, `PlatformFacts`, `ShopOverview`, `config/chatbot_kb.php` (48 h wording → policy values) | delivered e-mail, shop page, chatbot | 8, 10 |
| `routes/api.php`, `app/Console/Kernel.php` / `routes/console.php` (new routes + scheduled command) | whole API | all |
| `resources/lang/{fr,en,ar}/*.php`, frontends `messages/*.json` | every translated screen | 10 |

New files: see PLAN §3.
