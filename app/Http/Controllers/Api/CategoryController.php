<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * GET /api/categories
     * Public — returns all active categories for browsing (no products).
     */
    public function index()
    {
        $categories = Category::active()
            ->ordered()
            ->select(['id', 'name', 'name_ar', 'slug', 'icon', 'image'])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $categories,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // NEW ENDPOINT — used by the homepage CategoryShowcase section
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /api/categories/with-products
     *
     * Returns active categories, each with up to 4 approved+active products.
     * Used by the homepage to render the category showcase grid.
     *
     * Only categories that have at least 1 approved product are returned.
     * Products are sorted by newest first.
     */
    public function withProducts()
    {
        $categories = Category::active()
            ->ordered()
            ->select(['id', 'name', 'name_ar', 'slug', 'icon', 'image'])
            // Only return categories that have at least 1 approved, active product
            ->whereHas('activeProducts')
            // Eager-load up to 4 approved+active products per category
            ->with([
                'activeProducts' => function ($query) {
                    $query
                        ->where('stock', '>', 0)        // must be in stock
                        ->with(['primaryImage'])         // load primary image
                        ->select([
                            'id',
                            'category_id',
                            'name',
                            'slug',
                            'price',
                            'stock',
                        ])
                        ->orderByDesc('created_at')
                        ->limit(4);                      // max 4 per category on homepage
                },
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $categories,
        ]);
    }

    /**
     * GET /api/categories/{slug}
     * Public — returns a single category.
     */
    public function show($slug)
    {
        $category = Category::where('slug', $slug)
            ->active()
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data'    => $category,
        ]);
    }

    /**
     * GET /api/categories/{slug}/products
     * Public — returns paginated products within a category.
     */
    public function products(Request $request, $slug)
    {
        $category = Category::where('slug', $slug)->active()->firstOrFail();

        $products = $category->products()
            ->with(['seller:id,name', 'primaryImage'])
            ->where('is_approved', true)
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success'  => true,
            'category' => $category,
            'data'     => $products,
        ]);
    }

    // ── Admin-only endpoints ───────────────────────────────────────────

    public function adminIndex()
    {
        $categories = Category::withCount('products')
            ->ordered()
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:categories,name',
            'name_ar'     => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:100',
            'image'       => 'nullable|string|max:500',
            'is_active'   => 'sometimes|boolean',
            'order'       => 'sometimes|integer',
        ]);

        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category created.',
            'data'    => $category,
        ], 201);
    }

    public function update(Request $request, $category)
    {
        $category = Category::findOrFail($category);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255|unique:categories,name,' . $category->id,
            'name_ar'     => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:100',
            'image'       => 'nullable|string|max:500',
            'is_active'   => 'sometimes|boolean',
            'order'       => 'sometimes|integer',
        ]);

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category updated.',
            'data'    => $category,
        ]);
    }

    public function destroy($category)
    {
        $category = Category::findOrFail($category);
        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted.',
        ]);
    }
}