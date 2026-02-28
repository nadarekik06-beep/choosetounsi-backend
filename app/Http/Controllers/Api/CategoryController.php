<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * GET /api/categories
     * Public — returns all active categories for browsing.
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

    // ── Admin-only endpoints ───────────────────────────────────────────────────

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