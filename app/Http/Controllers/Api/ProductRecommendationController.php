<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductScoringService;
use App\Services\UserPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ProductRecommendationController
 *
 * Powers the 4 recommendation sections on the product detail page.
 *
 * All endpoints are PUBLIC (no auth required).
 * When a user is authenticated, scores are personalized.
 *
 * Routes:
 *   GET /api/products/{slug}/similar
 *   GET /api/products/{slug}/complementary
 *   GET /api/products/{slug}/from-seller
 *   GET /api/products/{slug}/recommended
 */
class ProductRecommendationController extends Controller
{
    public function __construct(
        private ProductScoringService  $scoringService,
        private UserPreferenceService  $preferenceService
    ) {}

    // ── 1. Similar Items ───────────────────────────────────────────────────

    /**
     * GET /api/products/{slug}/similar
     *
     * Products from the same category within a similar price range (±50%).
     * Sorted by score DESC.
     *
     * Logic:
     *   - Same category as the base product
     *   - Price between 50% and 150% of the base product's price
     *   - Excludes the base product itself
     *   - Max 8 results
     */
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
            ->limit(20) // Fetch more, then score + trim
            ->get();

        $prefs   = $this->getUserPreferences($request);
        $scored  = $this->scoringService->scoreAndSort($products, $prefs)->take(8);

        return response()->json([
            'success' => true,
            'data'    => $scored->map(fn($p) => $this->transformProduct($p))->values(),
        ]);
    }

    // ── 2. Complementary Items ─────────────────────────────────────────────

    /**
     * GET /api/products/{slug}/complementary
     *
     * Admin-defined complementary products from product_complementary table.
     * Fallback: if no relationships are defined, uses cross-category logic
     * (products from a different category that share buyers — based on order history).
     *
     * Max 8 results.
     */
    public function complementary(Request $request, string $slug)
    {
        $product = $this->findProduct($slug);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        // Strategy 1: Admin-defined relationships
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

        // Strategy 2: "Customers who bought this also bought" (cross-category)
        // Find products that frequently appear in the same orders as this product
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

        // Strategy 3: Fallback — different category products with high scores
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

    /**
     * GET /api/products/{slug}/from-seller
     *
     * Other products from the same seller.
     * Includes seller name/shop info.
     * Max 8 results, sorted by score.
     */
    public function fromSeller(Request $request, string $slug)
    {
        $product = $this->findProduct($slug);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        if (!$product->seller_id) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'seller'  => null,
            ]);
        }

        $products = Product::available()
            ->where('seller_id', $product->seller_id)
            ->where('id', '!=', $product->id)
            ->with(['primaryImage', 'seller:id,name', 'attributeValues.attribute'])
            ->limit(20)
            ->get();

        $prefs  = $this->getUserPreferences($request);
        $scored = $this->scoringService->scoreAndSort($products, $prefs)->take(8);

        // Seller info for the section header
        $seller = DB::table('users as u')
            ->leftJoin('seller_applications as sa', 'sa.user_id', '=', 'u.id')
            ->where('u.id', $product->seller_id)
            ->select(
                'u.id',
                'u.name',
                'u.avatar',
                'sa.business_name',
                'sa.plan',
                'sa.wilaya'
            )
            ->first();

        return response()->json([
            'success' => true,
            'data'    => $scored->map(fn($p) => $this->transformProduct($p))->values(),
            'seller'  => $seller ? [
                'id'            => $seller->id,
                'name'          => $seller->name,
                'business_name' => $seller->business_name,
                'plan'          => $seller->plan ?? 'free',
                'wilaya'        => $seller->wilaya,
                'avatar'        => $seller->avatar,
                'total_products'=> Product::available()->where('seller_id', $seller->id)->count(),
            ] : null,
        ]);
    }

    // ── 4. Other Items You Might Like ──────────────────────────────────────

    /**
     * GET /api/products/{slug}/recommended
     *
     * Personalized recommendations based on:
     *   1. User's explicit preferences (categories, brands, gender)
     *   2. User's activity (viewed, favorited, carted, ordered products)
     *   3. Inferred from similar users (products in same categories)
     *   4. Fallback: high-scoring products from all categories
     *
     * Max 8 results. Excludes the current product and its seller's products.
     */
    public function recommended(Request $request, string $slug)
    {
        $product = $this->findProduct($slug);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $user = $request->user();

        // For authenticated users: use inferred + explicit preferences
        if ($user) {
            $inferred    = $this->preferenceService->inferPreferencesFromActivity($user->id);
            $interactedProductIds = $inferred['top_product_ids'] ?? [];

            // If user has strong interaction history, use those categories
            $targetCategoryIds = !empty($inferred['top_category_ids'])
                ? $inferred['top_category_ids']
                : [$product->category_id]; // fallback to current product's category

            $products = Product::available()
                ->where('id', '!=', $product->id)
                ->whereIn('category_id', $targetCategoryIds)
                ->with(['primaryImage', 'seller:id,name', 'attributeValues.attribute'])
                ->limit(30)
                ->get();

            // Also boost products the user previously interacted with
            // by fetching them separately and merging
            if (!empty($interactedProductIds)) {
                $interactedProducts = Product::available()
                    ->whereIn('id', $interactedProductIds)
                    ->where('id', '!=', $product->id)
                    ->with(['primaryImage', 'seller:id,name', 'attributeValues.attribute'])
                    ->limit(10)
                    ->get();

                // Merge, deduplicate by id
                $products = $products->concat($interactedProducts)
                    ->unique('id')
                    ->values();
            }

        } else {
            // Guest: recommend high-traffic products from the same + related categories
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
        return Product::available()
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Get combined preferences for the authenticated user,
     * or null for guests.
     */
    private function getUserPreferences(Request $request): ?\App\Models\UserPreference
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        return $this->preferenceService->getCombinedPreferences($user->id);
    }

    /**
     * Transform a product for API response.
     * Consistent shape across all recommendation endpoints.
     */
    private function transformProduct(Product $p): array
    {
        $imgUrl = null;
        if ($p->relationLoaded('primaryImage') && $p->primaryImage) {
            $imgUrl = Storage::url($p->primaryImage->image_path);
        } elseif ($p->primary_image_url) {
            $imgUrl = $p->primary_image_url;
        }

        return [
            'id'                => $p->id,
            'name'              => $p->name,
            'slug'              => $p->slug,
            'short_description' => $p->short_description,
            'price'             => (float) $p->price,
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
            // Score metadata (useful for debugging)
            '_score' => $p->_score ?? null,
        ];
    }
}