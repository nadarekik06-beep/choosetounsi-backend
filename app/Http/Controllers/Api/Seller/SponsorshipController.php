<?php
// app/Http/Controllers/Api/Seller/SponsorshipController.php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Exceptions\Ads\AdRuleViolation;
use App\Jobs\GenerateAdCopy;
use App\Models\Sponsorship;
use App\Services\Ads\AdRequest;
use App\Services\Ads\AdServer;
use App\Services\Ads\SponsorshipService;
use App\Services\PlanGate;
use App\Support\Wilayas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * SponsorshipController — seller-facing sponsoring system.
 *
 * Payment model:
 *   Green sellers  → pay base rate (5.000 DT/day) + boost surcharge
 *   Red sellers    → pay reduced rate (2.000 DT/day) + boost surcharge
 *   Black sellers  → 3 free/week (quota); after quota: 1.500 DT/day + boost surcharge
 *
 * Boost surcharge (all plans):
 *   Priority 1-5  → free
 *   Priority 6    → +5.000 DT
 *   Priority 7    → +10.000 DT
 *   Priority 8    → +15.000 DT
 *   Priority 9    → +20.000 DT
 *   Priority 10   → +25.000 DT
 *   Formula: max(0, priority - 5) * 5.000 DT
 *
 * Endpoints:
 *   POST   /api/seller/sponsorships/sponsor        activate sponsorship
 *   DELETE /api/seller/sponsorships/{id}/cancel    cancel active sponsorship
 *   GET    /api/seller/sponsorships                list seller's sponsorships
 *   GET    /api/seller/sponsorships/quota          black free-quota status
 *
 * Public endpoints (no auth required; a Bearer token is honoured when present):
 *   GET    /api/sponsored-products                 feed for homepage/category
 *   POST   /api/sponsorships/{id}/impression       record view
 *   POST   /api/sponsorships/{id}/click            record click
 */
class SponsorshipController extends Controller
{
    // Boost surcharge constants
    const BOOST_FREE_THRESHOLD      = 5;       // priority ≤ 5 costs nothing extra
    const BOOST_SURCHARGE_PER_POINT = 5.000;   // DT per point above threshold

    // =========================================================================
    // POST /api/seller/sponsorships/sponsor
    // =========================================================================

    public function sponsor(Request $request): JsonResponse
    {
        $request->validate([
            'product_id'    => 'required|integer|exists:products,id',
            'duration_days' => 'sometimes|integer|min:1|max:90',
            'priority'      => 'sometimes|integer|min:1|max:10',
            'target_gender'         => 'sometimes|nullable|in:male,female,unisex',
            'target_wilaya_ids'     => 'sometimes|nullable|array',
            'target_wilaya_ids.*'   => 'string|max:100',
            'target_category_ids'   => 'sometimes|nullable|array',
            'target_category_ids.*' => 'integer|exists:categories,id',
            'target_price_min'      => 'sometimes|nullable|numeric|min:0',
            'target_price_max'      => 'sometimes|nullable|numeric|min:0|gt:target_price_min',
            // Payment fields
            'payment_method'        => 'sometimes|in:card,free_quota',
            'payment_token'         => 'sometimes|nullable|string|max:255',
        ]);

        $seller   = $request->user();
        $sellerId = $seller->id;

        // Pricing / quotas follow the plan's tier (free | red | black), so
        // admin-created plans price like the tier they belong to.
        $gate = app(PlanGate::class);
        $plan = $gate->tierFor($sellerId);

        if ($deny = $gate->canSponsor($sellerId)) {
            return $deny;
        }

        // Ownership check
        $product = Product::with(['category:id,name,name_fr,name_ar'])
            ->where('id', $request->product_id)
            ->where('seller_id', $sellerId)
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => __('seller.sponsor.not_owned'),
                'code'    => 'NOT_FOUND',
            ], 404);
        }

        if (!$product->is_approved || !$product->is_active) {
            return response()->json([
                'success' => false,
                'message' => __('seller.sponsor.only_approved'),
                'code'    => 'PRODUCT_NOT_ELIGIBLE',
            ], 422);
        }

        // One open campaign per product (re-checked under a lock in SponsorshipService)
        if (Sponsorship::hasOpenForProduct($product->id)) {
            return response()->json([
                'success' => false,
                'message' => __('seller.sponsor.already'),
                'code'    => 'DUPLICATE_ACTIVE',
            ], 422);
        }

        // ── Priority & boost ──────────────────────────────────────────────────
        $manualPriority = (int) $request->input('priority', 5);
        $boostBase      = Sponsorship::BOOST[$plan] ?? Sponsorship::BOOST['free'];
        $finalPriority  = (int) round($boostBase * ($manualPriority / 10));
        $finalPriority  = max(1, $finalPriority);

        // ── Boost surcharge calculation ───────────────────────────────────────
        $boostExtraCost = $this->calcBoostSurcharge($manualPriority);

        // ── Plan-specific pricing + quota logic ───────────────────────────────
        $usedFreeQuota = false;
        $wasPaid       = false;
        $paymentStatus = 'pending';
        $basePrice     = 0;
        $durationDays  = (int) $request->input('duration_days', 7);

        if ($plan === 'black') {
            $remaining = Sponsorship::blackFreeRemaining($sellerId);

            if ($remaining > 0 && $boostExtraCost === 0.0) {
                // Fully free: within quota AND priority ≤ 5
                $basePrice     = 0;
                $usedFreeQuota = true;
                $wasPaid       = false;
                $paymentStatus = 'free';

            } elseif ($remaining > 0 && $boostExtraCost > 0) {
                // Within quota but priority > 5 — only pay surcharge
                $basePrice     = 0;
                $usedFreeQuota = true;
                $wasPaid       = true;
                $paymentStatus = 'pending';

                if (!$this->processPayment($request, $boostExtraCost)) {
                    return $this->paymentRequiredResponse($boostExtraCost, $plan);
                }
                $paymentStatus = 'paid';

            } else {
                // Quota exhausted — pay base rate + surcharge
                $basePrice     = 1.500;
                $wasPaid       = true;
                $paymentStatus = 'pending';

                $totalDue = ($basePrice * $durationDays) + $boostExtraCost;

                if (!$this->processPayment($request, $totalDue)) {
                    return $this->paymentRequiredResponse($totalDue, $plan);
                }
                $paymentStatus = 'paid';
            }

        } elseif ($plan === 'red') {
            $basePrice     = Sponsorship::PRICE['red'];   // 2.000 DT/day
            $wasPaid       = true;
            $totalDue      = ($basePrice * $durationDays) + $boostExtraCost;
            $paymentStatus = 'pending';

            if (!$this->processPayment($request, $totalDue)) {
                return $this->paymentRequiredResponse($totalDue, $plan);
            }
            $paymentStatus = 'paid';

        } else {
            // Green / free sellers
            $basePrice     = Sponsorship::PRICE['free'];  // 5.000 DT/day
            $wasPaid       = true;
            $totalDue      = ($basePrice * $durationDays) + $boostExtraCost;
            $paymentStatus = 'pending';

            if (!$this->processPayment($request, $totalDue)) {
                return $this->paymentRequiredResponse($totalDue, $plan);
            }
            $paymentStatus = 'paid';
        }

        $amountCharged = ($basePrice * $durationDays) + $boostExtraCost;

        // ── Duration & timestamps ─────────────────────────────────────────────
        $startAt = Carbon::now();
        $endAt   = $startAt->copy()->addDays($durationDays);

        // ── Persist (template copy now, AI copy via the queued GenerateAdCopy job) ──
        ['tags' => $aiTags, 'ad_copy' => $aiAdCopy] = GenerateAdCopy::fallback($product->loadMissing('category'));

        try {
            $sponsorship = app(SponsorshipService::class)->createLegacy([
                'seller_id'           => $sellerId,
                'product_id'          => $product->id,
                'plan_type'           => $plan ?? 'free',
                'boost_score'         => $finalPriority,
                'start_at'            => $startAt,
                'end_at'              => $endAt,
                'amount_charged'      => $amountCharged,
                'was_paid'            => $wasPaid,
                'used_free_quota'     => $usedFreeQuota,
                'ai_tags'             => $aiTags,
                'ai_ad_copy'          => $aiAdCopy,
                'target_gender'       => $request->input('target_gender'),
                'target_wilaya_ids'   => Wilayas::normalizeMany($request->input('target_wilaya_ids')) ?: null,
                'target_category_ids' => $request->input('target_category_ids'),
                'target_price_min'    => $request->input('target_price_min'),
                'target_price_max'    => $request->input('target_price_max'),
            ]);
        } catch (AdRuleViolation $e) {
            return response()->json([
                'success' => false,
                'message' => __('seller.sponsor.already'),
                'code'    => 'DUPLICATE_ACTIVE',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('seller.sponsor.created'),
            'data'    => [
                'sponsorship'     => $sponsorship->load('product:id,name,slug'),
                'boost_score'     => $finalPriority,
                'plan'            => $plan,
                'expires_at'      => $endAt->toISOString(),
                'used_free_quota' => $usedFreeQuota,
                'remaining_free'  => $plan === 'black' ? Sponsorship::blackFreeRemaining($sellerId) : null,
                'amount_charged'  => $amountCharged,
                'boost_extra_cost'=> $boostExtraCost,
                'payment_status'  => $paymentStatus,
                'ai_tags'         => $aiTags,
                'ai_ad_copy'      => $aiAdCopy,
            ],
        ], 201);
    }

    // =========================================================================
    // DELETE /api/seller/sponsorships/{id}/cancel
    // =========================================================================

    public function cancel(Request $request, int $id): JsonResponse
    {
        $sponsorship = Sponsorship::where('id', $id)
            ->where('seller_id', $request->user()->id)
            ->open()
            ->first();

        if (!$sponsorship) {
            return response()->json([
                'success' => false,
                'message' => __('seller.sponsor.active_not_found'),
                'code'    => 'NOT_FOUND',
            ], 404);
        }

        app(SponsorshipService::class)->cancel($sponsorship);

        return response()->json([
            'success' => true,
            'message' => __('seller.sponsor.cancelled'),
        ]);
    }

    // =========================================================================
    // GET /api/seller/sponsorships
    // =========================================================================

    public function index(Request $request): JsonResponse
    {
        $sellerId = $request->user()->id;

        $query = Sponsorship::with(['product:id,name,slug,price,is_active,is_approved', 'product.primaryImage'])
            ->forSeller($sellerId)
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $sponsorships = $query->paginate((int) $request->query('per_page', 15));

        // Attach primary image URL to each product
        $sponsorships->getCollection()->transform(function ($s) {
            if ($s->product) {
                $img = $s->product->primaryImage;
                $s->product->image_url = $img ? Storage::url($img->image_path) : null;
                $s->product->unsetRelation('primaryImage');
            }
            return $s;
        });

        $plan = app(PlanGate::class)->tierFor($sellerId);

        return response()->json([
            'success' => true,
            'data'    => $sponsorships,
            'meta'    => [
                'plan'           => $plan,
                'remaining_free' => $plan === 'black' ? Sponsorship::blackFreeRemaining($sellerId) : null,
                'boost_scores'   => Sponsorship::BOOST,
                'prices'         => Sponsorship::PRICE,
            ],
        ]);
    }

    // =========================================================================
    // GET /api/seller/sponsorships/quota
    // =========================================================================

    public function quota(Request $request): JsonResponse
    {
        $sellerId = $request->user()->id;
        $plan     = app(PlanGate::class)->tierFor($sellerId);

        return response()->json([
            'success' => true,
            'data'    => [
                'plan'           => $plan,
                'free_per_week'  => Sponsorship::BLACK_FREE_PER_WEEK,
                'used_this_week' => Sponsorship::blackFreeUsedThisWeek($sellerId),
                'remaining'      => Sponsorship::blackFreeRemaining($sellerId),
                'week_resets_at' => Carbon::now()->endOfWeek()->toISOString(),
                'boost_scores'   => Sponsorship::BOOST,
                'prices'         => Sponsorship::PRICE,
                'boost_surcharge_per_point' => self::BOOST_SURCHARGE_PER_POINT,
                'boost_free_threshold'      => self::BOOST_FREE_THRESHOLD,
            ],
        ]);
    }

    // =========================================================================
    // GET /api/sponsored-products  (PUBLIC) — transitional wrapper over the ad server
    // =========================================================================

    /**
     * Kept for storefront sections not yet moved to GET /api/ads: category pages get
     * category_top ads, everything else home_row. Ads only (no organic backfill);
     * each carries sponsor_data.token for POST /api/ads/events.
     */
    public function publicFeed(Request $request): JsonResponse
    {
        $limit      = min(max(1, (int) $request->query('limit', 8)), 20);
        $categoryId = $request->filled('category_slug')
            ? \Illuminate\Support\Facades\DB::table('categories')->where('slug', $request->query('category_slug'))->value('id')
            : null;

        $req = AdRequest::fromHttp($request, $categoryId ? 'category_top' : 'home_row', [
            'limit'             => $limit,
            'contextCategoryId' => $categoryId ? (int) $categoryId : null,
        ]);

        return response()->json(['success' => true, 'data' => app(AdServer::class)->serve($req)['ads']]);
    }

    // =========================================================================
    // POST /api/sponsorships/{id}/impression|click  — deprecated
    // =========================================================================

    /**
     * Unsigned ids can't be trusted for billing or stats; the storefront now reports
     * events with ad tokens (POST /api/ads/events). Accepted and ignored until every
     * caller has moved.
     */
    public function recordImpression(int $id): JsonResponse
    {
        return response()->json(['success' => true, 'deprecated' => 'POST /api/ads/events'], 202);
    }

    public function recordClick(int $id): JsonResponse
    {
        return response()->json(['success' => true, 'deprecated' => 'POST /api/ads/events'], 202);
    }

    // =========================================================================
    // PRIVATE — Boost surcharge
    // =========================================================================

    private function calcBoostSurcharge(int $priority): float
    {
        $extra = max(0, $priority - self::BOOST_FREE_THRESHOLD);
        return $extra * self::BOOST_SURCHARGE_PER_POINT;
    }

    // =========================================================================
    // PRIVATE — Payment processing
    // =========================================================================

    /**
     * Process card payment.
     *
     * Accepts any non-empty payment_token (sandbox mode).
     * Replace the inner stub with your real gateway integration:
     *   Flouci  → https://flouci.com/api
     *   Konnect → https://api.konnect.network
     *   Stripe  → Stripe\PaymentIntent::create(...)
     */
    private function processPayment(Request $request, float $amount): bool
    {
        if ($amount <= 0) {
            return true;   // nothing to charge
        }

        $paymentToken = $request->input('payment_token');

        if (empty($paymentToken)) {
            return false;  // no token supplied → prompt payment UI
        }

        // ── Replace this block with your real gateway call ─────────────────
        // Example (Flouci):
        // try {
        //     $result = Http::withHeaders(['app_token' => config('services.flouci.secret')])
        //         ->post('https://developers.flouci.com/api/verify_payment/' . $paymentToken);
        //     return $result->successful() && $result->json('result.status') === 'SUCCESS';
        // } catch (\Throwable $e) {
        //     Log::error('[Payment] Flouci error: ' . $e->getMessage());
        //     return false;
        // }
        // ──────────────────────────────────────────────────────────────────
        Log::info("[Payment] Sandbox charge accepted: {$amount} DT, token: {$paymentToken}");
        return true;
    }

    private function paymentRequiredResponse(float $amountDue, string $plan): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('seller.sponsor.payment_required'),
            'code'    => 'PAYMENT_REQUIRED',
            'data'    => [
                'amount_due'   => number_format($amountDue, 3),
                'currency'     => 'DT',
                'plan'         => $plan,
                'instructions' => 'Provide a valid payment_token obtained from the payment gateway.',
            ],
        ], 402);
    }
}
