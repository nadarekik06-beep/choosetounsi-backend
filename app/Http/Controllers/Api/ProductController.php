<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Get all approved products (for browse/search)
     */
    public function index(Request $request)
    {
        $query = Product::with(['seller', 'category', 'primaryImage'])
            ->available() // Only approved and active
            ->inStock();  // Only in stock

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%");
            });
        }

        // Filter by category
        if ($request->has('category')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->category);
            });
        }

        // Price range
        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Sort
        $sortBy = $request->get('sort', 'created_at');
        $sortOrder = $request->get('order', 'desc');

        $allowedSorts = ['price', 'created_at', 'name', 'views'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $products = $query->paginate(20);

        return response()->json($products);
    }

    /**
     * Get featured products (for homepage)
     */
    public function featured()
    {
        $products = Product::with(['seller', 'category', 'primaryImage'])
            ->available()
            ->featured()
            ->inStock()
            ->latest()
            ->take(8)
            ->get();

        return response()->json([
            'products' => $products,
        ]);
    }

    /**
     * Get single product by slug
     */
    public function show($slug)
    {
        $product = Product::with(['seller', 'category', 'images'])
            ->where('slug', $slug)
            ->available()
            ->firstOrFail();

        // Increment views
        $product->incrementViews();

        // Get related products (same category)
        $relatedProducts = Product::with(['seller', 'primaryImage'])
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->available()
            ->inStock()
            ->take(4)
            ->get();

        return response()->json([
            'product' => $product,
            'related_products' => $relatedProducts,
        ]);
    }
}