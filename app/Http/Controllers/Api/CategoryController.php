<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    /**
     * GET /api/categories
     */
    public function index()
    {
        $categories = Category::active()->ordered()
            ->select(['id', 'name', 'name_ar', 'slug', 'icon', 'image'])
            ->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    /**
     * GET /api/categories/with-products
     */
    public function withProducts()
    {
        $categories = Category::active()->ordered()
            ->select(['id', 'name', 'name_ar', 'slug', 'icon', 'image'])
            ->whereHas('activeProducts')
            ->with([
                'activeProducts' => function ($query) {
                    $query->where('stock', '>', 0)
                          ->with(['primaryImage'])
                          ->select(['id', 'category_id', 'name', 'slug', 'price', 'stock'])
                          ->orderByDesc('created_at')
                          ->limit(4);
                },
            ])
            ->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    /**
     * GET /api/categories/{slug}
     */
    public function show($slug)
    {
        $category = Category::where('slug', $slug)->active()->firstOrFail();
        return response()->json(['success' => true, 'data' => $category]);
    }

    /**
     * GET /api/categories/{slug}/products
     *
     * Query params:
     *   subcategory_slug  — filter by subcategory slug (sent by frontend as ?sub=xxx)
     *   subcategory_id    — filter by subcategory id (alternative)
     *   sort              — created_at | price | views
     *   order             — asc | desc
     *   price_min / price_max
     *   in_stock          — 1 = only in stock
     *   search
     *   per_page
     */
    public function products(Request $request, $slug)
    {
        $category = Category::where('slug', $slug)->active()->firstOrFail();

        $query = $category->products()
            ->with(['seller:id,name', 'primaryImage'])
            ->where('is_approved', true)
            ->where('is_active', true);

        // ─── Subcategory filter ───────────────────────────────────────────
        // The frontend sends ?subcategory_slug=t-shirt
        // We resolve it to an ID first (more reliable than whereHas on a nullable FK)

        $subSlug = $request->query('subcategory_slug');
        $subId   = $request->query('subcategory_id');

        if ($subSlug) {
            // Resolve slug → id
            $subcategory = Subcategory::where('slug', $subSlug)
                ->where('category_id', $category->id)
                ->first();

            if ($subcategory) {
                // Filter by the actual FK column — no join needed, most reliable
                $query->where('subcategory_id', $subcategory->id);
            } else {
                // Slug sent but not found in DB → return empty result
                return response()->json([
                    'success'  => true,
                    'category' => $category,
                    'data'     => [
                        'data'         => [],
                        'current_page' => 1,
                        'last_page'    => 1,
                        'total'        => 0,
                        'from'         => null,
                        'to'           => null,
                    ],
                ]);
            }
        } elseif ($subId) {
            $query->where('subcategory_id', (int) $subId);
        }

        // ─── Text search ──────────────────────────────────────────────────
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%");
            });
        }

        // ─── Price range ──────────────────────────────────────────────────
        if ($min = $request->query('price_min')) $query->where('price', '>=', (float) $min);
        if ($max = $request->query('price_max')) $query->where('price', '<=', (float) $max);

        // ─── In stock ─────────────────────────────────────────────────────
        if (filter_var($request->query('in_stock'), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('stock', '>', 0);
        }

        // ─── Sorting ──────────────────────────────────────────────────────
        $sort  = $request->query('sort',  'created_at');
        $order = in_array($request->query('order'), ['asc', 'desc'])
            ? $request->query('order')
            : 'desc';

        match ($sort) {
            'price'  => $query->orderBy('price', $order),
            'views'  => $query->orderByDesc('views'),
            default  => $query->orderByDesc('created_at'),
        };

        $perPage  = min((int) $request->query('per_page', 20), 60);
        $products = $query->paginate($perPage);

        // Append primary image URL
        $products->getCollection()->transform(function ($product) {
            $product->primary_image_url = $product->primaryImage
                ? Storage::url($product->primaryImage->image_path)
                : null;
            return $product;
        });

        return response()->json([
            'success'  => true,
            'category' => $category,
            'data'     => $products,
        ]);
    }

    // ── Admin endpoints ────────────────────────────────────────────────────

    public function adminIndex()
    {
        $categories = Category::withCount(['products'])->ordered()->get();
        return response()->json(['success' => true, 'data' => $categories]);
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
        return response()->json(['success' => true, 'message' => 'Category created.', 'data' => $category], 201);
    }

    public function update(Request $request, $category)
    {
        $category  = Category::findOrFail($category);
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
        return response()->json(['success' => true, 'message' => 'Category updated.', 'data' => $category]);
    }

    public function destroy($category)
    {
        Category::findOrFail($category)->delete();
        return response()->json(['success' => true, 'message' => 'Category deleted.']);
    }
}