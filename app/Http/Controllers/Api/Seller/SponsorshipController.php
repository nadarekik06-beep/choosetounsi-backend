<?php
// app/Http/Controllers/Api/Seller/SponsorshipController.php
// UPDATED: Added payment validation, boost surcharge (5 DT per point > 5),
//          card payment processing stub, and weekly quota auto-reset.

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Sponsorship;
use App\Models\SellerApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use App\Models\UserPreference;

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
 *   POST   /api/seller/sponsorships/{id}/impression record view
 *   POST   /api/seller/sponsorships/{id}/click     record click
 *
 * Public endpoint (no auth required):
 *   GET    /api/sponsored-products                 feed for homepage/category
 */
class SponsorshipController extends Controller
{
    private string $groqUrl   = 'https://api.groq.com/openai/v1/chat/completions';
    private string $groqModel = 'llama3-8b-8192';

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

        // Resolve active plan from seller_applications
        $application = SellerApplication::where('user_id', $sellerId)
            ->where('status', 'approved')
            ->first();
        $plan = $application ? ($application->plan ?? 'free') : 'free';

        // Ownership check
        $product = Product::with(['category:id,name'])
            ->where('id', $request->product_id)
            ->where('seller_id', $sellerId)
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found or you do not own it.',
                'code'    => 'NOT_FOUND',
            ], 404);
        }

        if (!$product->is_approved || !$product->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Only approved and active products can be sponsored.',
                'code'    => 'PRODUCT_NOT_ELIGIBLE',
            ], 422);
        }

        // Duplicate active sponsorship guard
        if (Sponsorship::hasActiveForProduct($product->id)) {
            return response()->json([
                'success' => false,
                'message' => 'This product is already sponsored. Cancel the current sponsorship first.',
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
            // Auto-reset quota if week has rolled over
            Sponsorship::maybeResetBlackQuota($sellerId);

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

        // ── AI: tags + ad copy ────────────────────────────────────────────────
        ['tags' => $aiTags, 'ad_copy' => $aiAdCopy] = $this->generateAiContent($product);

        // ── Persist ───────────────────────────────────────────────────────────
        $sponsorship = null;
        DB::transaction(function () use (
            $sellerId, $product, $plan, $finalPriority,
            $startAt, $endAt, $amountCharged, $boostExtraCost,
            $wasPaid, $usedFreeQuota, $paymentStatus,
            $aiTags, $aiAdCopy, $request, &$sponsorship
        ) {
            $sponsorship = Sponsorship::create([
                'seller_id'        => $sellerId,
                'product_id'       => $product->id,
                'plan_type'        => $plan ?? 'free',
                'boost_score'      => $finalPriority,
                'status'           => 'active',
                'start_at'         => $startAt,
                'end_at'           => $endAt,
                'amount_charged'   => $amountCharged,
                'boost_extra_cost' => $boostExtraCost,
                'was_paid'         => $wasPaid,
                'payment_status'   => $paymentStatus,
                'payment_method'   => $wasPaid ? 'card' : 'free_quota',
                'used_free_quota'  => $usedFreeQuota,
                'ai_tags'          => $aiTags,
                'ai_ad_copy'       => $aiAdCopy,
                'impressions'      => 0,
                'clicks'           => 0,
                'conversions'      => 0,
                'target_gender'        => $request->input('target_gender'),
                'target_wilaya_ids'    => $request->input('target_wilaya_ids'),
                'target_category_ids'  => $request->input('target_category_ids'),
                'target_price_min'     => $request->input('target_price_min'),
                'target_price_max'     => $request->input('target_price_max'),
            ]);

            Sponsorship::syncProductFlags($product->id);
        });

        return response()->json([
            'success' => true,
            'message' => 'Product sponsored successfully.',
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
            ->where('status', 'active')
            ->first();

        if (!$sponsorship) {
            return response()->json([
                'success' => false,
                'message' => 'Active sponsorship not found.',
                'code'    => 'NOT_FOUND',
            ], 404);
        }

        $sponsorship->update(['status' => 'cancelled']);
        Sponsorship::syncProductFlags($sponsorship->product_id);

        return response()->json([
            'success' => true,
            'message' => 'Sponsorship cancelled. Product is no longer boosted.',
        ]);
    }

    // =========================================================================
    // GET /api/seller/sponsorships
    // =========================================================================

    public function index(Request $request): JsonResponse
    {
        $sellerId = $request->user()->id;

        // Expire overdue sponsorships on every list call
        try { Sponsorship::expireOverdue(); } catch (\Throwable $e) {
            Log::warning('[Sponsorship] expireOverdue failed: ' . $e->getMessage());
        }

        $query = Sponsorship::with(['product:id,name,slug,price,is_active,is_approved'])
            ->forSeller($sellerId)
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $sponsorships = $query->paginate((int) $request->query('per_page', 15));

        // Attach primary image URL to each product
        $sponsorships->getCollection()->transform(function ($s) {
            if ($s->product) {
                $img = ProductImage::where('product_id', $s->product->id)
                    ->where('is_primary', true)
                    ->first();
                $s->product->image_url = $img ? Storage::url($img->image_path) : null;
            }
            return $s;
        });

        $application = SellerApplication::where('user_id', $sellerId)
            ->where('status', 'approved')
            ->first();
        $plan = $application?->plan ?? 'free';

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

        $application = SellerApplication::where('user_id', $sellerId)
            ->where('status', 'approved')
            ->first();
        $plan = $application?->plan ?? 'free';

        // Auto-reset if the week has rolled over
        if ($plan === 'black') {
            Sponsorship::maybeResetBlackQuota($sellerId);
        }

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
    // GET /api/sponsored-products  (PUBLIC — no auth)
    // =========================================================================

    public function publicFeed(Request $request): JsonResponse
    {
        try { Sponsorship::expireOverdue(); } catch (\Throwable $e) {}

        $limit      = min((int) $request->query('limit', 12), 40);
        $catSlug    = $request->query('category_slug');
        $minResults = max(1, (int) $request->query('min_results', 2));

        $user  = $request->user();
        $prefs = null;

        if ($user) {
            $prefs = \App\Models\UserPreference::where('user_id', $user->id)->first();
        }

        $query = Product::available()
            ->where('is_sponsored', true)
            ->with([
                'category:id,name,slug',
                'primaryImage',
                'seller:id,name',
                'sponsorships' => fn($q) => $q->where('status', 'active')
                    ->select('id', 'product_id', 'ai_ad_copy', 'ai_tags', 'boost_score', 'end_at',
                             'target_gender', 'target_wilaya_ids', 'target_category_ids',
                             'target_price_min', 'target_price_max'),
            ])
            ->orderByDesc('sponsored_priority')
            ->orderByDesc('sponsored_at');

        if ($catSlug) {
            $query->whereHas('category', fn($q) => $q->where('slug', $catSlug));
        }

        $allSponsored = $query->take(100)->get();

        $targeted = $allSponsored->filter(function ($product) use ($user, $prefs) {
            $sponsorship = $product->sponsorships->first();
            if (!$sponsorship) return true;
            return $sponsorship->matchesUser($user, $prefs);
        })->values();

        if ($targeted->count() < $minResults) {
            $excludeIds = $targeted->pluck('id')->toArray();
            $relaxed    = $allSponsored
                ->whereNotIn('id', $excludeIds)
                ->take($limit - $targeted->count())
                ->values();
            $targeted = $targeted->concat($relaxed)->values();
        }

        if ($targeted->count() < $minResults) {
            $excludeIds   = $targeted->pluck('id')->toArray();
            $nonSponsored = Product::available()
                ->with(['category:id,name,slug', 'primaryImage', 'seller:id,name'])
                ->when($catSlug, fn($q) => $q->whereHas('category', fn($q2) => $q2->where('slug', $catSlug)))
                ->whereNotIn('id', $excludeIds)
                ->orderByDesc('views')
                ->take($limit - $targeted->count())
                ->get();
            $targeted = $targeted->concat($nonSponsored)->values();
        }

    // AFTER:
// Batch-load color images for all products in one query
$productIds = $targeted->take($limit)->pluck('id')->toArray();
$allColorImages = \App\Models\ProductImage::whereIn('product_id', $productIds)
    ->whereNotNull('color_option_id')
    ->select('product_id', 'image_path')
    ->get()
    ->groupBy('product_id');

$products = $targeted->take($limit)->map(function ($p) use ($allColorImages) {
    $p->primary_image_url = $p->primaryImage
        ? Storage::url($p->primaryImage->image_path)
        : null;
    $p->is_sponsored = (bool) ($p->is_sponsored ?? false);
    $p->sponsor_data = $p->sponsorships->first();
    unset($p->sponsorships);

    // Collect unique variant images from color-keyed images
    $variantImages = [];
    foreach ($allColorImages->get($p->id, collect()) as $img) {
        $url = Storage::url($img->image_path);
        if (!in_array($url, $variantImages, true)) {
            $variantImages[] = $url;
        }
    }
    $p->variant_images = $variantImages;

    return $p;
});

        return response()->json(['success' => true, 'data' => $products]);
    }

    // =========================================================================
    // POST /api/seller/sponsorships/{id}/impression
    // =========================================================================

    public function recordImpression(int $id): JsonResponse
    {
        Sponsorship::where('id', $id)->where('status', 'active')->increment('impressions');
        return response()->json(['success' => true]);
    }

    // =========================================================================
    // POST /api/seller/sponsorships/{id}/click
    // =========================================================================

    public function recordClick(int $id): JsonResponse
    {
        Sponsorship::where('id', $id)->where('status', 'active')->increment('clicks');
        return response()->json(['success' => true]);
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
            'message' => 'Payment is required to activate this sponsorship.',
            'code'    => 'PAYMENT_REQUIRED',
            'data'    => [
                'amount_due'   => number_format($amountDue, 3),
                'currency'     => 'DT',
                'plan'         => $plan,
                'instructions' => 'Provide a valid payment_token obtained from the payment gateway.',
            ],
        ], 402);
    }

    // =========================================================================
    // PRIVATE — AI content generation
    // =========================================================================

    private function generateAiContent(Product $product): array
    {
        $key = config('services.groq.key', env('GROQ_API_KEY', ''));
        if (empty($key)) {
            return $this->fallbackContent($product);
        }

        try {
            $title    = $product->name;
            $desc     = $product->description ?? $product->short_description ?? '';
            $category = $product->category?->name ?? '';

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$key}",
                'Content-Type'  => 'application/json',
            ])->timeout(12)->post($this->groqUrl, [
                'model'      => $this->groqModel,
                'messages'   => [
                    [
                        'role'    => 'system',
                        'content' => 'You are an e-commerce content expert for ChooseTounsi Tunisian marketplace. Always respond with ONLY valid JSON. No markdown, no preamble.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => "Generate promotional content for this product:\n- Title: {$title}\n- Category: {$category}\n- Description: {$desc}\n\nRespond ONLY with:\n{\"tags\":[\"kw1\",\"kw2\",\"kw3\",\"kw4\",\"kw5\",\"kw6\"],\"ad_copy\":\"<punchy promo sentence max 120 chars, French preferred>\"}",
                    ],
                ],
                'max_tokens'  => 200,
                'temperature' => 0.4,
            ]);

            if (!$response->successful()) {
                return $this->fallbackContent($product);
            }

            $raw   = $response->json('choices.0.message.content', '');
            $clean = preg_replace('/```json|```/i', '', $raw);
            $s     = strpos($clean, '{');
            $e     = strrpos($clean, '}');
            if ($s === false || $e === false) return $this->fallbackContent($product);

            $parsed = json_decode(substr($clean, $s, $e - $s + 1), true);
            if (!is_array($parsed)) return $this->fallbackContent($product);

            return [
                'tags'    => array_values(array_slice(array_filter((array) ($parsed['tags'] ?? [])), 0, 8)),
                'ad_copy' => trim((string) ($parsed['ad_copy'] ?? '')),
            ];
        } catch (\Throwable $e) {
            Log::warning('[SponsorshipController::generateAiContent] ' . $e->getMessage());
            return $this->fallbackContent($product);
        }
    }

    private function fallbackContent(Product $product): array
    {
        $words = preg_split('/[\s\-_]+/', strtolower(
            "{$product->name} {$product->category?->name}"
        ));
        $tags = array_values(array_unique(
            array_filter($words, fn($w) => strlen($w) >= 3)
        ));
        $tags = array_slice($tags, 0, 6);
        $tags = array_merge($tags, ['tunisien', 'choosetounsi']);

        return [
            'tags'    => $tags,
            'ad_copy' => "Découvrez {$product->name} sur ChooseTounsi — qualité tunisienne garantie !",
        ];
    }
}