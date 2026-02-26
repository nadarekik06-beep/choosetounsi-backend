<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════
    // PUBLIC METHODS - No authentication required
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get all active categories (for homepage)
     */
    public function index()
    {
        $categories = Category::active()
            ->ordered()
            ->withCount(['activeProducts as product_count'])
            ->get();

        return response()->json([
            'categories' => $categories,
        ]);
    }

    /**
     * Get single category by slug
     */
    public function show($slug)
    {
        $category = Category::where('slug', $slug)
            ->active()
            ->withCount(['activeProducts as product_count'])
            ->firstOrFail();

        return response()->json([
            'category' => $category,
        ]);
    }

    /**
     * Get products in a category
     */
    public function products(Request $request, $slug)
    {
        $category = Category::where('slug', $slug)->active()->firstOrFail();

        $query = $category->activeProducts()->with(['seller', 'primaryImage']);

        // Filters
        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Sort
        $sortBy = $request->get('sort', 'created_at');
        $sortOrder = $request->get('order', 'desc');

        if (in_array($sortBy, ['price', 'created_at', 'name', 'views'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $products = $query->paginate(20);

        return response()->json([
            'category' => $category,
            'products' => $products,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ADMIN METHODS - Require admin role
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Admin: List all categories (including inactive)
     */
    public function adminIndex()
    {
        $categories = Category::withCount('products')
            ->orderBy('order')
            ->get();

        return response()->json([
            'categories' => $categories,
        ]);
    }

    /**
     * Admin: Create category
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:10',
            'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'is_active' => 'boolean',
            'order' => 'integer',
        ]);

        // Upload image if provided
        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('categories', 'public');
        }

        $category = Category::create([
            'name' => $validated['name'],
            'name_ar' => $validated['name_ar'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'icon' => $validated['icon'] ?? null,
            'image' => $imagePath,
            'is_active' => $validated['is_active'] ?? true,
            'order' => $validated['order'] ?? 0,
        ]);

        return response()->json([
            'message' => 'Category created successfully.',
            'category' => $category,
        ], 201);
    }

    /**
     * Admin: Update category
     */
    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:10',
            'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'is_active' => 'boolean',
            'order' => 'integer',
        ]);

        // Upload new image if provided
        if ($request->hasFile('image')) {
            // Delete old image
            if ($category->image) {
                \Storage::disk('public')->delete($category->image);
            }
            $validated['image'] = $request->file('image')->store('categories', 'public');
        }

        $validated['slug'] = Str::slug($validated['name']);

        $category->update($validated);

        return response()->json([
            'message' => 'Category updated successfully.',
            'category' => $category,
        ]);
    }

    /**
     * Admin: Delete category
     */
    public function destroy(Category $category)
    {
        // Check if category has products
        if ($category->products()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete category with products. Please reassign products first.',
            ], 400);
        }

        // Delete image
        if ($category->image) {
            \Storage::disk('public')->delete($category->image);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }
}