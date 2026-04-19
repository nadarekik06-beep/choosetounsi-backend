<?php
// app/Http/Controllers/Api/Seller/SponsorshipController.php

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

/**
 * SponsorshipController — seller-facing sponsoring system.
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
 *
 * AI used ONLY for: tag generation + ad copy (Groq llama3-8b-8192).
 * All pricing, ranking, and quota logic is deterministic PHP.
 */
class SponsorshipController extends Controller
{
    private string $groqUrl   = 'https://api.groq.com/openai/v1/chat/completions';
    private string $groqModel = 'llama3-8b-8192';

    // =========================================================================
    // POST /api/seller/sponsorships/sponsor
    // =========================================================================

    public function sponsor(Request $request): JsonResponse
    {
        $request->validate([
            'product_id'    => 'required|integer|exists:products,id',
            'duration_days' => 'sometimes|integer|min:1|max:90',
            'priority'      => 'sometimes|integer|min:1|max:10',
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

        // Plan-specific pricing + quota logic
        $boostBase     = Sponsorship::BOOST[$plan] ?? Sponsorship::BOOST['free'];
        $price         = Sponsorship::PRICE[$plan] ?? Sponsorship::PRICE['free'];
        $usedFreeQuota = false;
        $wasPaid       = false;

        if ($plan === 'black') {
            $remaining = Sponsorship::blackFreeRemaining($sellerId);
            if ($remaining > 0) {
                $price         = 0;
                $usedFreeQuota = true;
            } else {
                // Quota exhausted — charge reduced rate
                $price   = 1.500;
                $wasPaid = true;
                // TODO: integrate payment gateway — reject here until payment confirmed
            }
        } elseif ($plan === 'red') {
            $wasPaid = true;
            // TODO: integrate payment gateway
        } else {
            // Green/Free sellers
            $wasPaid = true;
            // TODO: integrate payment gateway
        }

        // Priority multiplier: user can pick 1-10, scaled against plan boost
        $manualPriority = (int) $request->input('priority', 5);
        $finalPriority  = (int) round($boostBase * ($manualPriority / 10));
        $finalPriority  = max(1, $finalPriority); // always at least 1

        // Duration
        $durationDays = (int) $request->input('duration_days', 7);
        $startAt      = Carbon::now();
        $endAt        = $startAt->copy()->addDays($durationDays);

        // AI: tags + ad copy (async-safe; failure falls back gracefully)
        ['tags' => $aiTags, 'ad_copy' => $aiAdCopy] = $this->generateAiContent($product);

        // Persist inside a transaction
        $sponsorship = null;
        DB::transaction(function () use (
            $sellerId, $product, $plan, $finalPriority,
            $startAt, $endAt, $price, $wasPaid, $usedFreeQuota,
            $aiTags, $aiAdCopy, &$sponsorship
        ) {
            $sponsorship = Sponsorship::create([
                'seller_id'       => $sellerId,
                'product_id'      => $product->id,
                'plan_type'       => $plan,
                'boost_score'     => $finalPriority,
                'status'          => 'active',
                'start_at'        => $startAt,
                'end_at'          => $endAt,
                'amount_charged'  => $price,
                'was_paid'        => $wasPaid,
                'used_free_quota' => $usedFreeQuota,
                'ai_tags'         => $aiTags,
                'ai_ad_copy'      => $aiAdCopy,
                'impressions'     => 0,
                'clicks'          => 0,
                'conversions'     => 0,
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
            ],
        ]);
    }

    // =========================================================================
    // GET /api/sponsored-products  (PUBLIC — no auth)
    // =========================================================================

    public function publicFeed(Request $request): JsonResponse
    {
        try { Sponsorship::expireOverdue(); } catch (\Throwable $e) {}

        $limit = min((int) $request->query('limit', 12), 40);
        $categorySlug = $request->query('category_slug');

        $query = Product::available()
            ->where('is_sponsored', true)
            ->with(['category:id,name,slug', 'primaryImage', 'seller:id,name'])
            ->orderByDesc('sponsored_priority')
            ->orderByDesc('sponsored_at');

        if ($categorySlug) {
            $query->whereHas('category', fn($q) => $q->where('slug', $categorySlug));
        }

        $products = $query->take($limit)->get()->map(function ($p) {
            $p->primary_image_url = $p->primaryImage
                ? Storage::url($p->primaryImage->image_path)
                : null;
            $p->is_sponsored = true;

            // Attach active sponsorship's ad copy for display
            $activeSponsor = Sponsorship::where('product_id', $p->id)
                ->where('status', 'active')
                ->select('id', 'ai_ad_copy', 'ai_tags', 'boost_score', 'end_at')
                ->first();
            $p->sponsor_data = $activeSponsor;

            return $p;
        });

        return response()->json(['success' => true, 'data' => $products]);
    }

    // =========================================================================
    // POST /api/seller/sponsorships/{id}/impression  (lightweight, no auth)
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
    // PRIVATE — AI content generation
    // Uses Groq llama3-8b-8192 ONLY for tags + ad copy.
    // Math fallback when Groq is unreachable.
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