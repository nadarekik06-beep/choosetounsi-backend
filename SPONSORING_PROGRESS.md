# Smart sponsoring — progress log

Branch `feature/smart-sponsoring` in all four repos. Source of truth for the starting point: `SPONSORING_AUDIT.md`.

| Repo | Branched from | Notes |
|---|---|---|
| backend | `feature/smart-homepage` @ `968716f` | first commit on the branch adds `SPONSORING_AUDIT.md` (`0dc82e4`) |
| storefront | `feature/smart-homepage` @ `6928379` | clean |
| admin-panel | `feature/finance-order-drawer` @ `a3d8c6a` | your uncommitted finance-drawer work was committed there first as **"WIP: finance order drawer"**, so nothing was lost; this branch includes it |
| ai-service | `feature/smart-homepage` @ `65625f2` | the regenerated FAISS indexes were committed first ("Update search indexes"); the repo had no git identity, so I set `user.name`/`user.email` locally to match the backend repo |

One commit per repo per phase:

| Phase | backend | storefront | ai-service | admin-panel |
|---|---|---|---|---|
| 0 | `39f82e7` | `45c27b0` | `d675b67` | — |
| 1 | `481955e` | — | — | — |
| 2 | `3e940e5` | — | — | — |
| 3 | `bb79b21` | `94fec32` | — | — |
| 4 | `6b17c91` | `3277e00` | — | — |
| 5 | `d624fc0` | `6f2bc19` | — | — |
| 6 | see `git log` ("Sponsoring phase 6") | — | — | see `git log` |

---

## Phase 6 — Admin panel ✅
- **Admin API** (`AdminAdsController`): `GET /api/admin/ads/overview` (paid revenue vs plan credit, revenue per day, CTR by placement, campaigns by status, top advertisers, fraud flags: IPs with many unbilled clicks, CTR > 3× the placement norm), `GET /api/admin/ads/campaigns[/{id}]` (grouped search), `POST …/{id}/reject|pause|resume`, `GET|PUT /api/admin/ads/settings` (known keys only, shape-validated), `GET /api/admin/ads/wallets[/{seller}]`; `AdRevenue` service; finance overview adds `ad_revenue` + `ad_credit_spent`. Old `AdminSponsorshipController` removed.
- **Admin UI** (`app/(dashboard)/sponsoring/`): Overview, Campaigns (drawer: details, pause/resume, reject with reason → refund + seller notified), Wallets & top-ups (confirm/reject pending transfers, adjust balance/credit with note, ledger), Settings (every `ads.*` key grouped, only changed keys saved); sidebar entry; "Ad Revenue" card on Finance. Also fixed a pre-existing admin type error (`OrderTrendPoint.processing`) that blocked `npm run build`.
- Checks: backend 229 passed (new `AdminAdsTest`), admin `npm run build` ✅.
- **Test manually**: admin → Sponsoring → Settings: change *Min cpc* → Save → seller wizard shows the new minimum; Campaigns → open one → Reject with a reason → the seller's wallet gets the refund and a notification; Wallets → confirm a pending D17 top-up once (second click says already settled).

## End-to-end test script (all phases)
1. `php artisan migrate`, then keep `php artisan queue:work` running (and `schedule:run` via Task Scheduler — `docs/QUEUE_AND_SCHEDULER.md`).
2. **Seller**: `/seller/promote/wallet` → sandbox top-up 50 DT (local only) → wallet 50.
3. `/seller/promote/new` → pick a product with ≥ 3 photos, stock and a real description → readiness passes → daily 5 DT, suggested CPC → automatic audience → all placements → forecast → **Launch** (nothing charged) → campaign page, "Campaign live" bell + e-mail.
4. **Buyer** (another browser, logged in, browsed that category a few times): homepage "Sponsorisé pour vous" row, search for the product's keyword (slot 1), a similar product's page ("Sponsorisé — articles similaires"), cart drawer cross-sell, and after 8 s the entry popup (if relevance ≥ 0.6). Every ad shows the Sponsored label.
5. Click the ad → `POST /api/ads/events` click → seller wallet charged once (second-price, plan discount); clicking again within 24 h isn't billed.
6. Buyer orders the product → mark the order **delivered** in admin → seller campaign page: 1 order, revenue, ROAS.
7. Seller stops the campaign → status Stopped, `refunded: 0` (nothing was reserved; only clicks were paid). Admin → reject another campaign → its charges come back to the wallet.
8. Opted-in buyer: `php artisan ads:send-digest --user=<id>` → e-mail with a labelled sponsored product; click it → billed once; "Unsubscribe" link → opted out.

## Still needed for production
- **Real payment gateway** for ad top-ups: implement `KonnectGateway` / `FlouciGateway` (hosted page + server-side verification, TODO notes in the classes) or D17 automation; keep `ADS_SANDBOX_TOP_UP` off (it is off unless `APP_ENV=local`).
- **Queue worker + scheduler hosting** (supervisor + cron, or Windows services) — nothing marketing- or billing-related runs without them.
- **Legal pages**: disclose sponsored placements (how ads are chosen, "Sponsored" label) and marketing e-mails / consent / unsubscribe in the privacy policy and seller terms (ad billing, refunds on rejection, monthly credit expiry).
- Brevo sender-domain setup (SPF/DKIM) before sending digests at volume; review `ads.*` defaults (floors, credits, caps) in admin → Sponsoring → Settings.
- `AI_SERVICE_TOKEN` must be set on both sides in production; the AI service listens on 127.0.0.1 by default.

## Phase 5 — Emails & consent ✅
- **Consent**: opt-in only (`users.marketing_emails_opt_in`, default off); signup checkbox (unchecked) → `marketing_opt_in` kept through e-mail verification; `GET|POST /api/account/marketing-consent`; toggle on the profile page and a footer newsletter box for signed-in shoppers; one-click `GET|POST /unsubscribe/{token}` (localized page, CSRF-exempt POST for RFC 8058) + `List-Unsubscribe` / `List-Unsubscribe-Post` headers on every marketing e-mail.
- **E-mails** (`AdEmailService`, queued mailables `PickedForYouMail` / `StillLookingMail`, Blade templates on the existing layout, EN/FR/AR with RTL): weekly digest = organic recommendations + up to 2 labelled sponsored products (`email_digest`); "Still looking for X?" when a subcategory was viewed ≥ 3× in 7 days without buying and a sponsored product there is discounted or ships free; max 1 marketing e-mail per user per 7 days; impression at send, ad links via `GET /api/ads/r/{token}` (billed only on click, same dedupe/bot rules).
- **Scheduler**: `ads:send-digest` on `ads.digest_day` at `ads.digest_time` (Friday 18:00 Tunis), `ads:send-interest-emails` daily 11:00; both honour `digest_enabled` / `interest_emails_enabled`. Run guide for Windows Task Scheduler and cron/supervisor: `docs/QUEUE_AND_SCHEDULER.md`.
- Checks: backend 224 passed (new `MarketingEmailTest`), storefront build ✅. No real e-mail was sent from here (Brevo SMTP untouched).
- **Test manually**: tick the box at signup (or in the profile) → `php artisan ads:send-digest --user=<id>` and `php artisan queue:work --once` → e-mail with "Sponsorisé" items; click an ad → redirected to the product and billed once; click "Unsubscribe" in the footer of the e-mail → opted out.

## Phase 4 — Seller dashboard ✅
- **Seller UI** (`app/seller/promote/`): Ads home (wallet + free credit, 30-day KPIs + spend/sales chart, campaigns list with status chips, "worth boosting" suggestions), 6-step wizard `/new` (product → readiness with fix-it links → budget/duration/max CPC with plan discount → automatic or manual audience → placements → forecast + launch; no upfront charge), campaign page `/campaigns/[id]` (chart, per-placement table, ROAS, cost per order, tips, edit budget/CPC/end date, pause/resume/stop), wallet page `/wallet` (gateway top-up, pending top-ups, ledger). Old card form, `lib/sponsorshipApi.ts` and client-side prices removed; `/analytics` redirects. i18n `seller.ads` EN/FR/AR (old `seller.promote*` strings removed).
- **Backend**: `AdOptimizer` + `ads:optimize` (04:00 Tunis) → tips (`no_reach`, `low_ctr`, `low_roas`, `placement_no_orders`, listing fixes) stored in `sponsorships.optimizer`, per-placement weights used by the ad server, `CampaignLowPerformance` notification at most weekly; `GET /api/seller/ads/overview`; campaign detail returns `placement_stats`. Legacy prepaid seller endpoints (`/api/seller/sponsorships/*`), their constants and strings removed.
- Black hub `SmartPromoteCard` opens the wizard with the product preselected and shows the monthly credit; bell icons for ad notifications.
- Checks: backend 219 passed (new `AdOptimizerTest`), storefront `npm run build` ✅, migrations fresh + dev (backup `choosetounsi_before_phase4_*.sql`).
- **Test manually**: `/seller/promote` → New campaign → pick a product (a 1-photo product shows tips/blocked) → launch with the wallet topped up (sandbox on `/seller/promote/wallet`) → campaign page; `php artisan ads:optimize` after a few days of stats shows tips.

## Phase 3 — Storefront placements + entry popup ✅
- **New** `lib/adsApi.ts` (ads per placement, batched signed impression/click events, `withAdSlots`), `lib/overlayStore.ts` (one entry overlay at a time), `components/ads/` (`SponsoredCard` with "Sponsored / Sponsorisé / مُموَّل" label + gold ring + ad line + promo price, `useAdImpression` ≥50 % for 1 s, `AdStrip`, `EntryPopup`); i18n namespace `ads` (EN/FR/AR).
- **Placements**: home feed cards report with tokens; search results `search_top` and category grid `category_top` in reserved slots from `/api/ads/config` (list view too); product page "Sponsored — similar items" (`product_similar`); cart drawer cross-sell (`cart_cross_sell`); discover page via the ad server (page 1 only). Removed: `SponsoredProductsSection`, `SponsoredBadge`, "Trending in category" row, organic "Sponsored" badges (category grid, recommendations, cart), buyer calls in `lib/sponsorshipApi.ts`.
- **Entry popup** (mounted in `app/layout.tsx`): only when `/api/ads/popup` returns a highly relevant ad; delay/dismissal from config, once per session, 24 h / 7 days after 3 dismissals, not on checkout/cart/auth/seller/onboarding, yields to `ReviewPromptPopup`; bottom sheet on phones, card on desktop, focus trap + Esc.
- Backend fix: an ad is never shown for the product being viewed (`product_similar`). Storefront `npm run build` ✅; backend 216 passed; checked at 375 px.
- **Test manually**: open a product that shares a subcategory with a sponsored product → "Sponsorisé — articles similaires" card, `POST /api/ads/events` 202 in the network tab after 1 s on screen; category/search pages show ads in slots 1 and 7 only when the ad isn't already listed; wait 8 s on the homepage (known viewer with matching interests) → popup once.

## Phase 2 — Ad server, events, billing, attribution ✅
- **AdServer** (`app/Services/Ads/AdServer.php`): eligible campaigns (cached 60 s, flushed on changes) → filters (own product, bought, on page, 3/day frequency cap, targeting) → relevance (interest profile × page context per placement, dropped below `min_relevance`) → rank `bid × pCTR × relevance × quality`, 1 ad per seller → second-price charge (floor, then tier discount) sealed in an HMAC **ad token**. Legacy prepaid rows compete at the floor, never charged.
- **Events & billing**: `POST /api/ads/events` (impression dedupe 30 min; click billable once per viewer/campaign/24 h, never bots/IP bursts/self-clicks; charged credit-first, capped by today's and total budget; auto-pause `budget_exhausted_today` / `wallet_empty`; 80 % budget + wallet-low alerts once a day). **Attribution**: last billable click ≤ 7 days → pending at checkout, converted once on delivered/completed, reversed on cancel/refund (`OrderObserver` rewritten). New `sponsorship_events.countable` column.
- **Endpoints**: `GET /api/ads?placement=…` (+ `q`, `context_product_id`, `category_slug`, `cart`, `exclude[]`), `GET /api/ads/popup` (1/day/viewer), `/api/sponsored-products` = thin wrapper (category_top / home_row, ads only), old `/api/sponsorships/{id}/impression|click` = no-op 202. Home feed rows come from the ad server (`sponsor_data.token`). Organic listings/recs/scoring no longer rank or label by `is_sponsored`.
- **Scheduler**: `ads:reset-daily` 00:05, `ads:stock-watch` /15 min, `ads:reconcile-stats` 03:45 (Tunis) + existing. Tests: 216 passed (13 new in `AdServingTest`: relevance gate, second price, dedupe, bots/self, budget & wallet pause, frequency cap, attribution + reversal, tokens, popup cap).
- **Test manually**: `GET /api/ads?placement=category_top&category_slug=<slug>` with `X-Session-Id` → ads with `ad_token`; `POST /api/ads/events {"events":[{"token":…,"event":"click"}]}` → wallet charged once (2nd click `duplicate`); order that product as the same viewer → mark delivered → campaign `attributed_orders` +1; refund → back to 0.

## Phase 1 — Data model, wallet, campaigns ✅ (waiting for "go")

### What changed

**Schema** (9 migrations, `2026_09_30_000001…09`; all reversible, tested up → down → up)

| Migration | Content |
|---|---|
| `extend_sponsorships_for_campaigns` | `status` and `paused_reason` enums widened with raw `ALTER … MODIFY`. New columns: `pricing_model` (`legacy_daily`/`cpc`), `goal`, `daily_budget`, `total_budget`, `max_cpc`, `spent_total`, `spent_today`, `spent_today_date`, `placements`, `readiness_score`, `rejection_reason`, `ended_at`, `attributed_orders`, `attributed_revenue`. Stored generated column `open_product_id` + UNIQUE `uq_sponsorships_open_product`, so the database itself allows only one draft/active/paused campaign per product. Data step: existing rows become `legacy_daily` with `max_cpc = ads.min_cpc`; old "expired + plan_downgrade" rows become `paused`; duplicate open rows are cancelled; orphan sponsor flags are reset. |
| `drop_sponsored_until_from_products` | Dead column removed. |
| `create_ad_wallets_table` | `balance` (paid) + `credit_balance` (free plan credit) + `credit_expires_at`. |
| `create_ad_wallet_transactions_table` | Ledger rows: `amount` (signed total) and `credit_amount` (signed part on credit), with `balance_after` / `credit_after` snapshots. UNIQUE `(sponsorship_id, type, rollup_date)` gives one `click_charge` row per campaign per Tunis day. |
| `create_ad_top_ups_table` | Top-up intents: `pending → paid / failed / cancelled` exactly once, UNIQUE `(gateway, reference)`, linked to their single wallet transaction. |
| `create_sponsorship_events_table` | Impression/click/conversion rows (written from Phase 2). **`restrictOnDelete`** on `sponsorship_id`. |
| `create_sponsorship_daily_stats_table` | Campaign × day × placement roll-up. |
| `create_order_ad_attributions_table` | Order line → click. **`restrictOnDelete`** on `sponsorship_id`. |
| `add_marketing_consent_to_users_table` | `marketing_emails_opt_in` (default false), `marketing_opt_in_at`, `unsubscribe_token` (unique, backfilled, auto-generated on user creation, hidden from JSON), `last_marketing_email_at`. |

**Services** (`app/Services/Ads/`)
- `AdClock`: every ad *date* uses Africa/Tunis (daily budgets, roll-ups, credit expiry, end dates, stats). It also converts instants back to the app timezone before saving, because Eloquent doesn't.
- `AdWalletService`: the only writer of wallets.
  - Every change runs in a transaction with the wallet row `lockForUpdate` and writes one ledger row.
  - Clicks spend **credit first, then balance**, and never overdraw (throws `InsufficientAdFunds` → HTTP 402).
  - Expired credit is swept with a `credit_expiry` row.
  - Monthly credit is granted once per seller per period.
  - Refunds split back into paid balance and credit (credit that has since expired is dropped, never turned into cash).
  - `refundCampaignCharges()` is safe to repeat.
  - `adminAdjust()` can't take either pot below zero.
  - `settleTopUp()` is idempotent: only `pending → paid|failed`, one wallet transaction per top-up.
- `AdPricing`:
  - `floorCpc()` (category override or global minimum).
  - `suggestedCpc()`: the median billable click cost in the category over 14 days, falling back to floor × 1.5.
  - `tierDiscount()`, `monthlyCredit()`.
- `ReadinessService` scores 0–100 and returns machine codes (listed 20, stock 20, images 20, description 15, price 15, rating 10).
  - **Blockers:** `not_listed`, `out_of_stock`, `no_image`, `price_far_above_similar` (price above 2× the median of similar products).
  - **Tips:** `low_stock`, `add_images`, `improve_description` (action `ai_description`), `price_above_similar`, `low_rating`.
  - **Stock = total stock across active variants** when the product has variants, otherwise the product's own stock.
  - Similar products come from `SimilarProductsFinder`, with subcategory and then category medians as fallbacks.
- `AdForecastService` returns daily impression, click, order and spend ranges.
  - It uses category campaign history when available, otherwise category traffic from `user_interactions` × `forecast_reach_share`.
  - CTR comes from the placement priors; CVR from category purchases per view, else `forecast_default_cvr`.
  - Clicks are capped by what the budget can pay for, at the expected CPC after the tier discount.
- `SponsorshipService` is the only writer of campaigns: `create`, `update`, `pause`, `resume`, `cancel`, `complete`, `reject`, `completeEnded`, `pauseForPlan`, `resumeForPlan`, `createLegacy`, `summary`.
  - **Create rules:**
    - Plan feature check.
    - Readiness must pass.
    - `max_cpc` ≥ the category floor; it defaults to the suggested CPC.
    - `daily_budget` ≥ `ads.min_daily_budget`, and must cover at least one click at `max_cpc`.
    - `total_budget` ≥ `daily_budget`.
    - The wallet must hold at least one day of budget.
    - The product row is locked, one open campaign per product is re-checked, and the plan's `max_sponsored_products` counts **open** campaigns.
    - The campaign goes **active immediately**, starts with template ad copy, and queues the AI copy job.
  - **Resume:**
    - A campaign paused by an admin can only be resumed by an admin (`PAUSED_BY_ADMIN`).
    - A plan-paused campaign comes back only with the plan (`PAUSED_BY_PLAN`).
    - `BUDGET_EXHAUSTED_TODAY` blocks resuming until tomorrow.
    - The product must be listed and in stock, and the wallet must hold one day of budget.
    - If the end date passed while paused, the campaign completes instead.
  - **Reject:** ends the campaign and refunds every charge it caused.
- `Payments/`:
  - `AdTopUpGateway` interface.
  - `SandboxGateway`: pays instantly, **enabled only when `APP_ENV=local`** or `ADS_SANDBOX_TOP_UP=true`.
  - `ManualAdminGateway`: D17 or bank transfer reference, pending until an admin confirms.
  - `KonnectGateway` / `FlouciGateway`: documented TODO stubs (hosted page + server-side verification).
  - `AdTopUpGateways` registry.
  - No card data is ever collected.

**Jobs, commands, notifications**
- `GenerateAdCopy` (queued, after commit): reuses the shared `GroqClient` (its model setting and free-tier budget). The synchronous Groq call in the legacy controller is gone.
- `ads:grant-monthly-credit`: scheduled on the 1st at 00:10 Africa/Tunis; Red 10 DT / Black 40 DT by default (`--seller=` for one seller). `ads:complete-ended` now **completes** campaigns and sends the summary.
- `Notifications/Ads/CampaignActivated`, `CampaignPaused`, `CampaignEnded` (completed, admin-cancelled, rejected with the refund amount): database + mail, queued after commit, in the seller's locale, in the existing `NotificationBell` shape.
  - **No notification** for manual pauses or `budget_exhausted_today`.
  - Texts are in `resources/lang/{en,fr,ar}/ads.php` (`errors.*`, `notif.*`).
- `PlanDowngradeService` pauses (`paused` + `plan_downgrade`) and resumes through the service. Resume works properly now: the old code lost the priority and used `expired`. `PlanGate::canSponsor()` counts open campaigns only.

**HTTP**

| Method | URL | Notes |
|---|---|---|
| GET | `/api/ads/config` | public: popup rules, `max_ads`, `reserved_slots`, placements; no prices |
| POST | `/api/ads/top-ups/callback/{gateway}` | public, throttled; idempotent settle |
| GET | `/api/seller/ads/config` | `?product_id=` → tier, tier discount, monthly credit, min daily budget, min top-up, floor/suggested CPC, readiness threshold, gateways, wallet |
| POST | `/api/seller/ads/readiness` | `{product_id}` |
| POST | `/api/seller/ads/forecast` | `{product_id, daily_budget, max_cpc?, days?}` |
| GET | `/api/seller/ads/suggestions` | AutoPromotionService; `already_sponsored` = has an open campaign |
| GET | `/api/seller/ads/wallet` · `/wallet/transactions` · `/wallet/top-ups` | |
| POST | `/api/seller/ads/wallet/top-up` | `{amount ≥ ads.min_top_up (10.000), gateway, reference (manual)}`; a duplicate transfer reference returns 409 |
| GET·POST | `/api/seller/ads/campaigns` | |
| GET·PATCH | `/api/seller/ads/campaigns/{id}` | show adds `summary` (spend / paid / credit / clicks / orders / revenue / ROAS / cost per order), 30-day `daily`, per-`placements` |
| POST | `/api/seller/ads/campaigns/{id}/pause\|resume\|cancel` | cancel returns `refunded: 0` |
| POST | `/api/admin/ads/wallets/{seller}/adjust` | `{balance_delta?, credit_delta?, note, credit_expires_at?}` |
| GET | `/api/admin/ads/top-ups` | `?status=pending` |
| POST | `/api/admin/ads/top-ups/{id}/confirm\|reject` | idempotent, `already_settled` flag |

- All seller routes use the existing `seller.feature:sponsorships` middleware (JSON 401/403).
- Business errors come back as `{success:false, message (localized), code}` plus details: `NOT_READY` with the readiness report, `WALLET_TOO_LOW` (402) with required/available, `CPC_BELOW_MIN` with min, and so on.
- **Legacy endpoints kept for the current promote page** (until Phase 4):
  - `POST /api/seller/sponsorships/sponsor` now creates `legacy_daily` rows through `SponsorshipService::createLegacy()`: same one-open-campaign guard, ghost fields removed (**bug 1 fixed**), queued AI copy. It still returns `DUPLICATE_ACTIVE`.
  - Seller and admin cancel go through the service.

**Interim rule until the Phase 2 ad server:**
- The old read paths (`/api/sponsored-products`, the home-feed sponsored row, card `sponsor_data`) and the `products.is_sponsored` flags only consider **live `legacy_daily` rows** (`Sponsorship::legacyLive()`).
- New CPC campaigns are active in the dashboard but **not shown to buyers yet**, so they get no unbilled exposure.
- Test and demo helpers (`HomeFeedTest`, `DemoCatalog`) now create `legacy_daily` rows.

### Checks run
- Migrations: up → rollback of all 9 → up again on `choosetounsi_fresh` ✅.
- `migrate:fresh --seed` on `choosetounsi_fresh` ✅ (seeded users get unsubscribe tokens).
- Dev DB `choosetounsi`:
  - Backed up first: `C:\xampp\backups\choosetounsi_before_phase1_20260929_1730.sql`.
  - `php artisan migrate` applied the 9 migrations.
  - Data step log: `paused_restored: 0, duplicate_open_cancelled: 0, orphan_flags_reset: []`.
  - Result: 6 active `legacy_daily` rows, all under the uniqueness guard; all 30 users have a token.
- `php artisan test`: **203 passed, 13 failed**. The 13 are the same pre-existing Breeze `Auth\*` / `ExampleTest` failures.
- New tests: 31 tests / 192 assertions, all passing.
  - `AdWalletTest` (7): credit-first charge + daily roll-up, never overdraws, expired credit swept, monthly credit once per period, refund split, admin adjust floor, top-up settles once.
  - `SponsorshipServiceTest` (11): create, readiness blocker, CPC/budget/wallet floors, one open campaign per product (service **and** DB unique), plan limit, pause/resume/cancel, silent vs notified pauses, admin-only resume, reject refunds, scheduled completion, plan downgrade/upgrade, update rules.
  - `ReadinessTest` (5): full score, variant stock, tips, price blockers/tips, unlisted.
  - `SellerAdsApiTest` (8): auth, sandbox top-up + min + disabled gateway, manual top-up → admin confirm once, duplicate reference, campaign lifecycle over HTTP, wizard tools, legacy endpoint guard, admin adjust, monthly credit command by tier.
- Storefront `npm run build` ✅ (no storefront changes in this phase; the legacy promote page still builds against the kept endpoints).
- Dev API smoke test (guest GETs): `/api/ads/config` 200, `/api/sponsored-products` returns the 6 legacy ads, `/api/home/feed` 200, `/api/seller/ads/wallet` 401 without a token.
- `schedule:list` shows `ads:complete-ended` (every 5 min), `ads:grant-monthly-credit` (1st of month, 00:10 Tunis), `search:rebuild` (02:30 Tunis).

### How to test manually (seller token in Postman/curl; `php artisan queue:work` running for notifications and AI copy)
1. `POST /api/seller/ads/wallet/top-up {"amount": 50, "gateway": "sandbox"}` → `status: paid`; `GET /api/seller/ads/wallet` shows `available: 50`.
2. `POST /api/seller/ads/readiness {"product_id": <a product with 1 photo>}` → `add_images` tip and a lower score. A product with 0 stock across its active variants → `out_of_stock` blocker.
3. `GET /api/seller/ads/config?product_id=…` → floor and suggested CPC. `POST /api/seller/ads/forecast {"product_id": …, "daily_budget": 5, "days": 7}` → ranges.
4. `POST /api/seller/ads/campaigns {"product_id": …, "daily_budget": 5, "max_cpc": 0.3}` → 201, `active`. The notification shows in the bell and by e-mail, and the ad copy is filled by the queued job a few seconds later. The same product again → 422 `ALREADY_OPEN`.
5. `POST …/campaigns/{id}/pause` → `resume` → `cancel` (`refunded: 0`).
6. Manual top-up: `{"amount": 30, "gateway": "manual", "reference": "D17-123"}` → pending. As admin, `POST /api/admin/ads/top-ups/{id}/confirm` → paid. Confirming again → `already_settled: true`, and the wallet is credited only once.
7. Admin: `POST /api/admin/ads/wallets/{seller}/adjust {"credit_delta": 10, "note": "gift"}`.
8. `php artisan ads:grant-monthly-credit --seller=<red seller id>` → 10 DT credit expiring at the end of the month (Tunis). Running it again does nothing.

### Open questions / notes
1. **No `ads.*` rows seeded into `platform_settings`**, as in the approved plan: defaults come from `config/ads.php`, and admins only store what they change.
2. **Refunds:** nothing is reserved per CPC campaign, so cancel and complete refund 0. Reject refunds every charge (credit part back to credit, paid part to balance).
3. **Queue worker needed in development** for campaign notifications and AI copy (`php artisan queue:work`). Windows Task Scheduler and production supervisor docs come in Phase 5.
4. **Legacy prepaid flow still live** until Phase 4 (the old promote page, with its sandbox "payment"). It now respects the one-open-campaign rule and no longer drops fields.

---

## Phase 0 — Foundations & fixes ✅

### What changed

**Foundations**
- `config/ads.php`: every tunable number of the ad engine under `defaults` (budgets, CPC, tier discounts, monthly credits, dedupe/attribution windows, ranking priors, per-placement relevance thresholds and density caps, popup and e-mail settings, alert thresholds). Deployment-only keys (timezone `Africa/Tunis`, token TTLs, cache TTLs, Groq model) sit outside `defaults`.
- `app/Services/Ads/AdSettings.php` (singleton): `config('ads.defaults')` overridden by `platform_settings` rows keyed `ads.<name>`.
  - Cached for 5 min and memoized per request.
  - `get('tier_click_discount.red')` dot access, plus `float()` / `int()` helpers.
  - `set([...])` rejects unknown keys and flushes the cache.
  - No endpoint yet: `GET /api/ads/config` comes with the seller APIs in Phase 1.
- Database queue:
  - `QUEUE_CONNECTION=database` in `.env` and `.env.example`. `.env.testing` stays `sync`.
  - New `failed_jobs` table; the `jobs` table already existed.
- Scheduler (`app/Console/Kernel.php`):
  - `ads:complete-ended` every 5 minutes.
  - `search:rebuild` nightly at 02:30 Africa/Tunis (01:30 UTC). The app timezone is UTC, so new daily entries pin `config('ads.timezone')`.
  - The other `ads:*` commands are added in the phase that creates them.
- `products` index `idx_products_sponsored (is_sponsored, sponsored_priority)`.

**Bug fixes** (numbers from the spec's §7)

| # | Fix | Where |
|---|---|---|
| 2 | `/api/sponsored-products` resolves the Bearer user (`$request->user() ?? $request->user('sanctum')`), so targeting now applies. The old "relaxed targeting" fallback that re-added targeted-out ads is removed. The organic backfill excludes those ads and is always returned with `is_sponsored=false`, `sponsor_data=null` (no more lazy-loaded sponsor data on backfill). | `SponsorshipController@publicFeed` |
| 3 | Wilaya targeting uses `User::targetingWilaya()`: default address, else latest order, memoized per request. New `App\Support\Wilayas` holds the 24 canonical names (same as the storefront's `lib/i18n/wilayas.ts`) and normalises loose spellings (`Kef` → `Le Kef`, `manouba` → `La Manouba`, `BEJA` → `Béja`). New targets are stored normalised. The seller wizard now uses the canonical list instead of its own misspelled copy. | `Sponsorship::matchesUser`, `User.php`, `Support/Wilayas.php`, `SponsorshipController@sponsor`, storefront `app/seller/promote/page.tsx` |
| 4 | Black Pepper direct toggle removed: `POST /api/seller/black/sponsor/{id}`, the unused `GET /api/seller/black/sponsored`, their methods, `SponsoredProductActivatedNotification` and the orphan `seller.black.sponsor_on/off` strings. | `routes/api.php`, `BlackPepperController.php` |
| 8 | Admin sponsorship search grouped inside `where(fn …)`, so `status` / `plan_type` filters can't be bypassed. | `AdminSponsorshipController@index` |
| 9 | One tier helper, `PlanGate::tierFor($sellerId)`, used by `sponsor()`, `index()` and `quota()`. It resolves the seller subscription's current plan, then the application's plan, then the tier. `meta.plan` / `data.plan` now always return `free`/`red`/`black` (before: the raw plan slug). | `PlanGate.php`, `SponsorshipController` |
| 10 | Search page "Trending now" called the non-existent `/api/sponsorships/public`. It now shows the most-viewed products (organic). | storefront `app/search/page.tsx` |
| 11 | Discover page fetches ads only for page 1 (no re-injection on every page, and those ads are removed from later organic pages). Only items with `is_sponsored=true` get the "Sponsored" label; the popularity backfill is dropped from the ad slots. | storefront `app/discover/page.tsx` |
| 13 | Navbar search dropdown "Popular Products" shows organic most-viewed products instead of unlabelled ads. Product listings no longer put sponsored products first on the guest/explicit-sort path, so `price_asc`/`price_desc`/`views`/`best_selling`/newest are honoured exactly. | storefront `Navbar.tsx`; `ProductController@index` |
| 14 | No more writes on GET: `expireOverdue()` removed from the public feed, seller list, admin stats/list and `HomeFeedBuilder`. Reads use the new `Sponsorship::live()` scope (active and not past `end_at`), so an ended ad disappears immediately even before the scheduled command flips its status. | `Sponsorship.php`, controllers, `HomeFeedBuilder`, `ProductCardPresenter` |
| 15 | Seller and admin sponsorship lists eager-load `product.primaryImage` (no per-row image query). | `SponsorshipController@index`, `AdminSponsorshipController@index` |
| 18 | AI service: binds `127.0.0.1` by default (`AI_SERVICE_HOST` env to override). Every route except `/health` requires the `X-AI-Token` header (constant-time compare). Laravel sends it on every AI call via a new `Http::ai()` macro (search text/image/suggest, chatbot retriever, similar products, `search:rebuild`). | ai-service `config.py`, `main.py`; backend `AppServiceProvider`, `config/services.php` (`services.ai.token`), 4 call sites |

**Dead code removed:** `Sponsorship::maybeResetBlackQuota()` and its calls, `app/Mail/SellerMails.php` (duplicate mailable classes, never autoloadable), storefront `sections/Hero.tsx` and `sections/RecommendedSection.tsx` (unused), the stale `ProductScoringService` docblock and the wrong route list in `SponsorshipController`. The unscheduled `sponsorships:expire` became the scheduled `ads:complete-ended`, with the old name kept as an alias.

### Files
- **backend (new):** `config/ads.php`, `app/Services/Ads/AdSettings.php`, `app/Support/Wilayas.php`, `app/Console/Commands/CompleteEndedSponsorships.php` (renamed from `ExpireSponsorships.php`), `database/migrations/2026_09_29_000001_create_failed_jobs_table.php`, `database/migrations/2026_09_29_000002_add_sponsored_index_to_products_table.php`, `tests/Feature/Ads/SponsoringFoundationsTest.php`, this file.
- **backend (changed):** `app/Console/Kernel.php`, `app/Providers/AppServiceProvider.php`, `app/Services/PlanGate.php`, `app/Models/{Sponsorship,User}.php`, `app/Http/Controllers/Api/Seller/{SponsorshipController,BlackPepperController}.php`, `app/Http/Controllers/Admin/AdminSponsorshipController.php`, `app/Http/Controllers/Api/{ProductController,SearchController}.php`, `app/Services/Recommendation/{HomeFeedBuilder,ProductCardPresenter,SimilarProductsFinder}.php`, `app/Services/Chat/ProductRetriever.php`, `app/Console/Commands/RebuildSearchIndex.php`, `app/Services/ProductScoringService.php` (comment), `config/services.php`, `routes/api.php`, `resources/lang/{en,fr,ar}/seller.php`, `.env.example`.
- **backend (deleted):** `app/Mail/SellerMails.php`, `app/Notifications/SponsoredProductActivatedNotification.php`.
- **storefront:** `app/components/layout/Navbar.tsx`, `app/search/page.tsx`, `app/discover/page.tsx`, `app/seller/promote/page.tsx`; deleted `app/components/sections/{Hero,RecommendedSection}.tsx`.
- **ai-service:** `config.py`, `main.py`, `.gitignore` (adds `.ai_token`).
- **Local, not committed (gitignored):**
  - backend `.env`: `QUEUE_CONNECTION=database`, and `AI_SERVICE_TOKEN` set to a freshly generated random secret.
  - ai-service `.ai_token`: the same secret.

### Checks run
- `php artisan migrate` on the dev DB `choosetounsi`: 2 migrations applied. A backup was taken first: `C:\xampp\backups\choosetounsi_before_phase0_20260929_1659.sql`.
- `migrate:fresh --seed` on a throwaway copy `choosetounsi_fresh` (`--env=testing`, `DB_DATABASE` overridden): ✅. I used a separate DB so neither the dev DB nor `choosetounsi_test` was wiped.
- `php artisan test`: **172 passed, 13 failed**. The 13 failures are the same pre-existing Breeze `Auth\*` tests and `ExampleTest` (web `/` returns 500) that fail on the untouched branch (baseline: 162 passed / 13 failed). The new `SponsoringFoundationsTest` has 10 tests, 44 assertions, all passing.
- AI service: existing unit tests pass (7/7). TestClient check: `/health` returns 200; `/similar` and `/index/rebuild` return 401 with no or wrong token and 200 with the right one.
- Storefront `npm run build`: ✅.
- Smoke test on the running dev API as a guest: `/api/sponsored-products`, `/api/products?sort=views`, `/api/home/feed` all return 200.
- Dev DB has 6 active sponsorship rows and **0 products flagged sponsored without a live row**, so there are no Black-toggle leftovers to reset.

### How to test manually
1. **Restart the AI service** (it now reads `.ai_token` and listens on 127.0.0.1). Search, image search and "You might also like" should work as before. A request without the header (e.g. `curl -X POST localhost:8001/similar`) should return 401.
2. **Run the queue worker** (the database queue is now on; seller-application e-mails are `ShouldQueue`): `php artisan queue:work --tries=3`. Full Windows / production instructions come in Phase 5.
3. **Scheduler:** `php artisan schedule:list` shows `ads:complete-ended` (every 5 min) and `search:rebuild` (02:30 Tunis). Run once by hand with `php artisan ads:complete-ended`.
4. **Targeting:**
   - Log in as a buyer with gender preference *male*.
   - As a seller, create a sponsorship targeted *female*.
   - The category "Trending in category" row no longer shows that ad to the male buyer, but still shows it to a logged-out visitor.
5. **Wilaya:**
   - Seller targets *Le Kef*.
   - A buyer whose default address is in *Sfax* doesn't see the ad.
   - A buyer in *Le Kef*, or with no address at all, does.
6. **Honest sorts:** on a category page pick *Price ↑*. The order is strictly by price, with no sponsored product jumping to the top.
7. **Navbar:** focus the search box. "Popular Products" shows the most-viewed products, not ads.
8. **Search page:** search for something with no results. "Trending now" shows 4 products (before: always empty because of the 404).
9. **Discover:** scroll through several pages. Ads appear only in the first batch with a "Sponsored" label and don't repeat on later pages.
10. **Ended ad:** set a sponsorship's `end_at` in the past. It disappears from the home feed and category row immediately (status still `active`) and turns `expired` at the next `ads:complete-ended`.
11. **Admin API:** `GET /api/admin/sponsorships?status=cancelled&search=<seller name>` returns only cancelled rows.

### Open questions
1. **Commit Phase 0?** I haven't committed the phase's changes. Should I make one commit per repo per phase from now on?
2. **Logged-in default listing:** the personalized scoring path (logged-in buyer, default sort) still adds `sponsored_priority` as a score bonus. Phase 2 removes it together with the recommendation boosts, as the spec schedules. Is keeping it until then OK?
3. **Queue switch now or in Phase 5?** `QUEUE_CONNECTION=database` is live now, so seller-application e-mails wait for a worker. If you'd rather not run `queue:work` during development until Phase 5, I can revert just that line in `.env`.
4. **Dropping `products.sponsored_until`:** the spec's §2.2 asks to drop it. I left it for the Phase 1 migration list so all schema changes go through your plan approval together.
5. **Also removed `GET /api/seller/black/sponsored`:** it only listed products for the removed toggle and had no caller. Tell me if you want it back as a read-only list.

**Next:** on your "go", I'll enter plan mode and show the Phase 1 migration list and the new service and class list before writing any code.
