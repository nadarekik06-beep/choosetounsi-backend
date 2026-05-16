<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductScoringService;
use App\Services\PromotionService;
use App\Services\UserPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductRecommendationController extends Controller
{
    public function __construct(
        private ProductScoringService $scoringService,
        private UserPreferenceService $preferenceService,
        private PromotionService      $promoService,      // ← ADDED
    ) {}

    // ── 1. Similar Items ───────────────────────────────────────────────────

    public function similar(Request $request, string $slug)
    {
        $product = $this->findProduct($slug);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $priceMin = $product->price * 0.5;
        $priceMax = $product->price * 1.5;

        $products = Product::available()
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->whereBetween('price', [$priceMin, $priceMax])
            ->with(['primaryImage', 'seller:id,name', 'attributeValues.attribute'])
            ->limit(20)
            ->get();

        $prefs  = $this->getUserPreferences($request);
        $scored = $this->scoringService->scoreAndSort($products, $prefs)->take(8);

        return response()->json([
            'success' => true,
            'data'    => $scored->map(fn($p) => $this->transformProduct($p))->values(),
        ]);
    }

    // ── 2. Complementary Items ─────────────────────────────────────────────

    public function complementary(Request $request, string $slug)
    {
        $product = $this->findProduct($slug);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $complementIds = DB::table('product_complementary')
            ->where('product_id', $product->id)
            ->orderBy('order')
            ->limit(8)
            ->pluck('complement_id')
            ->toArray();

        if (!empty($complementIds)) {
            $products = Product::available()
                ->whereIn('id', $complementIds)
                ->with(['primaryImage', 'seller:id,name', 'attributeValues.attribute'])
                ->get();

            $prefs  = $this->getUserPreferences($request);
            $scored = $this->scoringService->scoreAndSort($products, $prefs)->take(8);

            return response()->json([
                'success' => true,
                'source'  => 'curated',
                'data'    => $scored->map(fn($p) => $this->transformProduct($p))->values(),
            ]);
        }

        $frequentlyBoughtWith = DB::table('order_items as oi1')
            ->join('order_items as oi2', 'oi1.order_id', '=', 'oi2.order_id')
            ->where('oi1.product_id', $product->id)
            ->where('oi2.product_id', '!=', $product->id)
            ->select('oi2.product_id', DB::raw('COUNT(*) as pair_count'))
            ->groupBy('oi2.product_id')
            ->orderByDesc('pair_count')
            ->limit(12)
            ->pluck('oi2.product_id')
            ->toArray();

        if (!empty($frequentlyBoughtWith)) {
            $products = Product::available()
                ->whereIn('id', $frequentlyBoughtWith)
                ->with(['primaryImage', 'seller:id,name', 'attributeValues.attribute'])
                ->get();

            $prefs  = $this->getUserPreferences($request);
            $scored = $this->scoringService->scoreAndSort($products, $prefs)->take(8);

            return response()->json([
                'success' => true,
                'source'  => 'behavioral',
                'data'    => $scored->map(fn($p) => $this->transformProduct($p))->values(),
            ]);
        }

        $products = Product::available()
            ->where('id', '!=', $product->id)
            ->where('category_id', '!=', $product->category_id)
            ->with(['primaryImage', 'seller:id,name', 'attributeValues.attribute'])
            ->orderByDesc('views')
            ->limit(20)
            ->get();

        $prefs  = $this->getUserPreferences($request);
        $scored = $this->scoringService->scoreAndSort($products, $prefs)->take(8);

        return response()->json([
            'success' => true,
            'source'  => 'fallback',
            'data'    => $scored->map(fn($p) => $this->transformProduct($p))->values(),
        ]);
    }

    // ── 3. From This Seller ────────────────────────────────────────────────

    public function fromSeller(Request $request, string $slug)
    {
        $product = $this->findProduct($slug);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        if (!$product->seller_id) {
            return response()->json(['success' => true, 'data' => [], 'seller' => null]);
        }

        $products = Product::available()
            ->where('seller_id', $product->seller_id)
            ->where('id', '!=', $product->id)
            ->with(['primaryImage', 'seller:id,name', 'attributeValues.attribute'])
            ->limit(20)
            ->get();

        $prefs  = $this->getUserPreferences($request);
        $scored = $this->scoringService->scoreAndSort($products, $prefs)->take(8);

        $seller = DB::table('users as u')
            ->leftJoin('seller_applications as sa', 'sa.user_id', '=', 'u.id')
            ->where('u.id', $product->seller_id)
            ->select('u.id', 'u.name', 'u.avatar', 'sa.business_name', 'sa.plan', 'sa.wilaya')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => $scored->map(fn($p) => $this->transformProduct($p))->values(),
            'seller'  => $seller ? [
                'id'             => $seller->id,
                'name'           => $seller->name,
                'business_name'  => $seller->business_name,
                'plan'           => $seller->plan ?? 'free',
                'wilaya'         => $seller->wilaya,
                'avatar'         => $seller->avatar,
                'total_products' => Product::available()->where('seller_id', $seller->id)->count(),
            ] : null,
        ]);
    }

    // ── 4. Recommended ────────────────────────────────────────────────────

    public function recommended(Request $request, string $slug)
    {
        $product = $this->findProduct($slug);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $user = $request->user();

        if ($user) {
            $inferred             = $this->preferenceService->inferPreferencesFromActivity($user->id);
            $interactedProductIds = $inferred['top_product_ids'] ?? [];
            $targetCategoryIds    = !empty($inferred['top_category_ids'])
                ? $inferred['top_category_ids']
                : [$product->category_id];

            $products = Product::available()
                ->where('id', '!=', $product->id)
                ->whereIn('category_id', $targetCategoryIds)
                ->with(['primaryImage', 'seller:id,name', 'attributeValues.attribute'])
                ->limit(30)
                ->get();

            if (!empty($interactedProductIds)) {
                $interactedProducts = Product::available()
                    ->whereIn('id', $interactedProductIds)
                    ->where('id', '!=', $product->id)
                    ->with(['primaryImage', 'seller:id,name', 'attributeValues.attribute'])
                    ->limit(10)
                    ->get();

                $products = $products->concat($interactedProducts)->unique('id')->values();
            }
        } else {
            $products = Product::available()
                ->where('id', '!=', $product->id)
                ->where('category_id', $product->category_id)
                ->with(['primaryImage', 'seller:id,name', 'attributeValues.attribute'])
                ->orderByDesc('views')
                ->limit(20)
                ->get();
        }

        $prefs  = $this->getUserPreferences($request);
        $scored = $this->scoringService->scoreAndSort($products, $prefs)->take(8);

        return response()->json([
            'success' => true,
            'data'    => $scored->map(fn($p) => $this->transformProduct($p))->values(),
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function findProduct(string $slug): ?Product
    {
        return Product::available()->where('slug', $slug)->first();
    }

    private function getUserPreferences(Request $request): ?\App\Models\UserPreference
    {
        $user = $request->user();
        return $user ? $this->preferenceService->getCombinedPreferences($user->id) : null;
    }

    /**
     * Transform a product for API response.
     *
     * CHANGE: now calls PromotionService to append effective_price,
     * discount_amount, and promotion to every product — same as
     * ProductController@transformProductItem does for listings.
     */
    private function transformProduct(Product $p): array
    {
        $imgUrl = null;
        if ($p->relationLoaded('primaryImage') && $p->primaryImage) {
            $imgUrl = Storage::url($p->primaryImage->image_path);
        } elseif ($p->primary_image_url) {
            $imgUrl = $p->primary_image_url;
        }

        // ── ADDED: compute promotion discount ─────────────────────────────
        $promoData = $this->promoService->getEffectivePrice($p);

        return [
            'id'                => $p->id,
            'name'              => $p->name,
            'slug'              => $p->slug,
            'short_description' => $p->short_description,
            'price'             => (float) $p->price,         // original base price
            'effective_price'   => $promoData['effective_price'],  // ← NEW
            'discount_amount'   => $promoData['discount_amount'],  // ← NEW
            'promotion'         => $promoData['promotion'],         // ← NEW
            'stock'             => $p->stock,
            'views'             => $p->views ?? 0,
            'featured'          => (bool) $p->featured,
            'is_sponsored'      => (bool) $p->is_sponsored,
            'primary_image_url' => $imgUrl,
            'category_id'       => $p->category_id,
            'seller'            => $p->seller ? [
                'id'   => $p->seller->id,
                'name' => $p->seller->name,
            ] : null,
            '_score'            => $p->_score ?? null,
        ];
    }
}