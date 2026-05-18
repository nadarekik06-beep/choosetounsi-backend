<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Services\ProductScoringService;
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
                ->with([
                    'category:id,name,slug',
                    'primaryImage',
                    'seller:id,name',
                    'variants'          => fn($q) => $q->where('is_active', true),
                    'attributeValues.attribute',
                ])
                ->where(function ($q) use ($prefs, $activityWeights) {
                    $q->where('is_sponsored', true);
                    if (!empty($prefs?->category_ids)) {
                        $q->orWhereIn('category_id', array_map('intval', (array) $prefs->category_ids));
                    }
                    if (!empty($activityWeights)) {
                        $q->orWhereIn('id', array_keys($activityWeights));
                    }
                })
                ->orderByDesc('is_sponsored')
                ->orderByDesc('views')
                ->limit(200)
                ->get();

            if ($candidates->count() < 40) {
                $existingIds = $candidates->pluck('id')->toArray();
                $backfill    = Product::available()
                    ->with([
                        'category:id,name,slug', 'primaryImage', 'seller:id,name',
                        'variants'          => fn($q) => $q->where('is_active', true),
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
            ->with(['category:id,name,slug', 'primaryImage', 'seller:id,name',
                    'variants' => fn($q) => $q->where('is_active', true)])
            ->orderByDesc('is_sponsored')
            ->orderByDesc('sponsored_priority')
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

    public function similar(Request $request, string $slug): JsonResponse
    {
        $limit = min((int) $request->query('limit', 8), 20);
        $user  = $request->user();

        $source = Product::available()
            ->where('slug', $slug)
            ->select('id', 'category_id', 'seller_id')
            ->first();

        if (!$source) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $candidates = Product::available()
            ->with(['category:id,name,slug', 'primaryImage', 'seller:id,name',
                    'variants' => fn($q) => $q->where('is_active', true),
                    'attributeValues.attribute'])
            ->where('category_id', $source->category_id)
            ->where('id', '!=', $source->id)
            ->limit(80)
            ->get();

        if ($candidates->count() < $limit) {
            $existingIds  = $candidates->pluck('id')->push($source->id)->toArray();
            $supplemental = Product::available()
                ->with(['category:id,name,slug', 'primaryImage', 'seller:id,name',
                        'variants' => fn($q) => $q->where('is_active', true),
                        'attributeValues.attribute'])
                ->whereNotIn('id', $existingIds)
                ->orderByDesc('views')
                ->limit(40)
                ->get();
            $candidates = $candidates->concat($supplemental);
        }

        $prefs           = $user ? $this->preferenceService->getCombinedPreferences($user->id) : null;
        $activityWeights = $user ? $this->preferenceService->getActivityWeights($user->id) : [];
        $scored          = $this->scoringService->scoreAndSort($candidates, $prefs, $activityWeights);

        return response()->json([
            'success' => true,
            'data'    => $this->transformCollection($scored->take($limit)),
        ]);
    }

    // =========================================================================
    // GET /api/products/{slug}/complementary
    // =========================================================================

    public function complementary(Request $request, string $slug): JsonResponse
    {
        $limit = min((int) $request->query('limit', 4), 12);
        $user  = $request->user();

        $source = Product::available()
            ->where('slug', $slug)
            ->select('id', 'category_id')
            ->first();

        if (!$source) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // If explicit complementary pairs exist, use them
        if (Schema::hasTable('product_complementary')) {
            $explicitIds = DB::table('product_complementary')
                ->where('product_id', $source->id)
                ->orderBy('order')
                ->pluck('complement_id')
                ->toArray();

            if (!empty($explicitIds)) {
                $products = Product::available()
                    ->with(['category:id,name,slug', 'primaryImage', 'seller:id,name',
                            'variants' => fn($q) => $q->where('is_active', true)])
                    ->whereIn('id', $explicitIds)
                    ->get()
                    ->sortBy(fn($p) => array_search($p->id, $explicitIds))
                    ->values();

                return response()->json([
                    'success' => true,
                    'data'    => $this->transformCollection($products->take($limit)),
                ]);
            }
        }

        // Fallback: cross-sell from different categories, scored by user interest
        $candidates = Product::available()
            ->with(['category:id,name,slug', 'primaryImage', 'seller:id,name',
                    'variants' => fn($q) => $q->where('is_active', true),
                    'attributeValues.attribute'])
            ->where('category_id', '!=', $source->category_id)
            ->where('id', '!=', $source->id)
            ->orderByDesc('is_sponsored')
            ->orderByDesc('featured')
            ->orderByDesc('views')
            ->limit(40)
            ->get();

        $prefs           = $user ? $this->preferenceService->getCombinedPreferences($user->id) : null;
        $activityWeights = $user ? $this->preferenceService->getActivityWeights($user->id) : [];
        $scored          = $this->scoringService->scoreAndSort($candidates, $prefs, $activityWeights);

        return response()->json([
            'success' => true,
            'data'    => $this->transformCollection($scored->take($limit)),
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
            ->with(['category:id,name,slug', 'primaryImage', 'seller:id,name',
                    'variants' => fn($q) => $q->where('is_active', true)])
            ->where('seller_id', $sellerId)
            ->where('id', '!=', $source->id)
            ->orderByDesc('is_sponsored')
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
            ->with(['category:id,name,slug', 'primaryImage', 'seller:id,name',
                    'variants' => fn($q) => $q->where('is_active', true),
                    'attributeValues.attribute'])
            ->where('id', '!=', $source->id)
            ->where(function ($q) use ($prefs, $source) {
                if (!empty($prefs?->category_ids)) {
                    $q->whereIn('category_id', array_map('intval', (array) $prefs->category_ids));
                } else {
                    $q->where('category_id', $source->category_id);
                }
                $q->orWhere('is_sponsored', true);
            })
            ->orderByDesc('featured')
            ->orderByDesc('is_sponsored')
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

    private function transformCollection($products): array
    {
        return $products->map(function ($p) {
            $p->primary_image_url  = $p->primaryImage
                ? Storage::url($p->primaryImage->image_path)
                : null;
            $p->is_sponsored       = (bool) ($p->is_sponsored ?? false);
            $p->sponsored_priority = (int)  ($p->sponsored_priority ?? 0);
 
            if ($p->relationLoaded('variants')) {
                $p->setRelation(
                    'variants',
                    $p->variants->map(fn($v) => ['id' => $v->id, 'stock' => $v->stock])->values()
                );
            }
 
       
            return $p;
        })->values()->toArray();
    }
}