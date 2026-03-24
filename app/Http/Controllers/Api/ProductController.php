<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * GET /api/products
     */
    public function index(Request $request)
    {
        $query = Product::query()
            ->where('is_active', true)
            ->where('is_approved', true)
            ->with(['category', 'images']);

        if ($search = $request->query('search')) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate(12),
        ]);
    }

    /**
     * GET /api/products/featured
     */
    public function featured()
    {
        $products = Product::where('is_active', true)
            ->where('is_approved', true)
            ->with(['category', 'images'])
            ->latest()
            ->take(8)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * GET /api/products/{slug}
     */
    public function show($slug)
    {
        $product = Product::where('slug', $slug)
            ->where('is_active', true)
            ->where('is_approved', true)
            ->with(['category', 'images'])
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    /**
     * ADMIN UPDATE
     */
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $product->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Product updated',
            'data' => $product,
        ]);
    }
}