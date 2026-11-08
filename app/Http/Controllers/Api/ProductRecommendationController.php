<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Services\ProductScoringService;
use App\Services\Recommendation\RelatedProducts;
use App\Services\UserPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ProductRecommendationController extends Controller
{
    public function __construct(
        private ProductScoringService $scoringService,
        private UserPreferenceService $preferenceService,
    ) {}

    // =========================================================================
    // GET /api/recommendations   (homepage personalized feed)
    // =========================================================================

    public function feed(Request $request): JsonResponse
    {
        $user  = $request->user();
        $limit = min((int) $request->query('limit', 20), 60);

        if ($user) {
            $prefs           = $this->preferenceService->getCombinedPreferences($user->id);
            $activityWeights = $this->preferenceService->getActivityWeights($user->id);

            $candidates = Product::available()
                // AFTER:
                ->with([
                    'category:id,name,name_fr,name_ar,slug',
                    'primaryImage',
                    'seller:id,name',
                    'variants'          => fn($q) => $q->where('is_active', true)
                                                    ->with('images'),   // ← ADD THIS
                    'attributeValues.attribute',
                ])
                ->where(function ($q) use ($prefs, $activityWeights) {
                    if (!empty($prefs?->category_ids)) {
                        $q->orWhereIn('category_id', array_map('intval', (array) $prefs->category_ids));
                    }
                    if (!empty($activityWeights)) {
                        $q->orWhereIn('id', array_keys($activityWeights));
                    }
                })
                ->orderByDesc('views')
                ->limit(200)
                ->get();

            if ($candidates->count() < 40) {
                $existingIds = $candidates->pluck('id')->toArray();
                $backfill = Product::available()
    ->with([
        'category:id,name,name_fr,name_ar,slug', 'primaryImage', 'seller:id,name',
        'variants' => fn($q) => $q->where('is_active', true)->with('images'),  // ← ADD
        'attributeValues.attribute',
    ])
                        ->whereNotIn('id', $existingIds)
                    ->orderByDesc('views')
                    ->orderByDesc('created_at')
                    ->limit(80)
                    ->get();
                $candidates = $candidates->concat($backfill);
            }

            $scored = $this->scoringService->scoreAndSort($candidates, $prefs, $activityWeights);

            return response()->json([
                'success'         => true,
                'personalized'    => true,
                'has_preferences' => $prefs?->hasAnyPreference() ?? false,
                'data'            => $this->transformCollection($scored->take($limit)),
            ]);
        }

        $products = Product::available()
          ->with(['category:id,name,name_fr,name_ar,slug', 'primaryImage', 'seller:id,name',
        'variants' => fn($q) => $q->where('is_active', true)->with('images')]) // ← ADD

            ->orderByDesc('views')
            ->limit($limit)
            ->get();

        return response()->json([
            'success'      => true,
            'personalized' => false,
            'data'         => $this->transformCollection($products),
        ]);
    }

    // =========================================================================
    // GET /api/products/{slug}/similar
    // =========================================================================

    /** Same kind of product (subcategory → family), gender and price nearby. See RelatedProducts. */
    public function similar(Request $request, string $slug): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 8), 20));

        return response()->json([
            'success' => true,
            'data'    => $this->transformCollection(app(RelatedProducts::class)->similar($slug, $limit)),
        ]);
    }

    // =========================================================================
    // GET /api/products/{slug}/complementary
    // =========================================================================

    /** Products that complete an outfit (fashion only); empty when nothing fits. See RelatedProducts. */
    public function complementary(Request $request, string $slug): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 4), 12));

        return response()->json([
            'success' => true,
            'data'    => $this->transformCollection(app(RelatedProducts::class)->complementary($slug, $limit)),
        ]);
    }

    // =========================================================================
    // GET /api/products/{slug}/from-seller
    // =========================================================================

    public function fromSeller(Request $request, string $slug): JsonResponse
    {
        $limit = min((int) $request->query('limit', 4), 12);

        $source = Product::available()
            ->where('slug', $slug)
            ->with('seller:id,name')
            ->select('id', 'seller_id', 'category_id')
            ->first();

        if (!$source || !$source->seller_id) {
            return response()->json(['success' => true, 'data' => [], 'seller' => null]);
        }

        $sellerId    = $source->seller_id;
        $application = SellerApplication::where('user_id', $sellerId)
            ->where('status', 'approved')
            ->select('user_id', 'business_name', 'wilaya', 'plan', 'profile_picture')
            ->first();

        $totalProducts = Product::available()->where('seller_id', $sellerId)->count();

        $sellerInfo = [
            'id'             => $sellerId,
            'name'           => $source->seller->name ?? '',
            'business_name'  => $application?->business_name ?? null,
            'plan'           => $application?->plan ?? 'free',
            'wilaya'         => $application?->wilaya ?? null,
            'avatar'         => $application?->profile_picture
                ? Storage::url($application->profile_picture)
                : null,
            'total_products' => $totalProducts,
        ];

        $products = Product::available()
            ->with(['category:id,name,name_fr,name_ar,slug', 'primaryImage', 'seller:id,name',
                    'variants' => fn($q) => $q->where('is_active', true)])
            ->where('seller_id', $sellerId)
            ->where('id', '!=', $source->id)
            ->orderByDesc('featured')
            ->orderByDesc('views')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $this->transformCollection($products),
            'seller'  => $sellerInfo,
        ]);
    }

    // =========================================================================
    // GET /api/products/{slug}/recommended
    // =========================================================================

    public function recommended(Request $request, string $slug): JsonResponse
    {
        $limit = min((int) $request->query('limit', 4), 12);
        $user  = $request->user();

        $source = Product::available()
            ->where('slug', $slug)
            ->select('id', 'category_id', 'seller_id')
            ->first();

        if (!$source) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $prefs           = $user ? $this->preferenceService->getCombinedPreferences($user->id) : null;
        $activityWeights = $user ? $this->preferenceService->getActivityWeights($user->id) : [];

        $candidates = Product::available()
            ->with(['category:id,name,name_fr,name_ar,slug', 'primaryImage', 'seller:id,name',
                    'variants' => fn($q) => $q->where('is_active', true),
                    'attributeValues.attribute'])
            ->where('id', '!=', $source->id)
            ->where(function ($q) use ($prefs, $source) {
                if (!empty($prefs?->category_ids)) {
                    $q->whereIn('category_id', array_map('intval', (array) $prefs->category_ids));
                } else {
                    $q->where('category_id', $source->category_id);
                }
            })
            ->orderByDesc('featured')
            ->orderByDesc('views')
            ->limit(60)
            ->get();

        $scored = $this->scoringService->scoreAndSort($candidates, $prefs, $activityWeights);

        return response()->json([
            'success' => true,
            'data'    => $this->transformCollection($scored->take($limit)),
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================
/** Storefront cards with promotion pricing — same payload as the home feed. */
private function transformCollection($products): array
{
    return app(\App\Services\Recommendation\ProductCardPresenter::class)->present(collect($products));
}
}