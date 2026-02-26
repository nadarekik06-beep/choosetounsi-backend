<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class SellerProductController extends Controller
{
    /**
     * Hardcoded seller_id = 1 for development.
     * Replace with auth()->id() when auth middleware is wired up.
     */
    private int $sellerId = 1;

    // ── GET /api/seller/products ───────────────────────────────────────────────
    public function index(Request $request)
    {
        $query = Product::withoutGlobalScopes()
            ->with(['category:id,name'])
            ->where('seller_id', $this->sellerId)
            ->select([
                'id', 'seller_id', 'category_id', 'name',
                'description', 'price', 'stock',
                'is_approved', 'is_active', 'created_at',
            ]);

        // Filters
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('is_approved')) {
            $query->where('is_approved', filter_var($request->is_approved, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->category_id);
        }

        $perPage = (int) $request->get('per_page', 12);
        $products = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $products,
        ]);
    }

    // ── GET /api/seller/products/stats ────────────────────────────────────────
    public function stats()
    {
        $stats = Product::withoutGlobalScopes()
            ->where('seller_id', $this->sellerId)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_active   = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active   = 0 THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) as pending_approval,
                SUM(stock) as total_stock,
                SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) as out_of_stock
            ')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => $stats,
        ]);
    }

    // ── GET /api/seller/products/{id} ─────────────────────────────────────────
    public function show(int $id)
    {
        $product = Product::withoutGlobalScopes()
            ->with(['category:id,name'])
            ->where('seller_id', $this->sellerId)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $product,
        ]);
    }

    // ── POST /api/seller/products ─────────────────────────────────────────────
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'stock'       => 'required|integer|min:0',
        ]);

        $product = Product::create([
            ...$validated,
            'seller_id'   => $this->sellerId,
            'is_active'   => true,
            'is_approved' => false, // Admin must approve new products
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully. It will be visible after admin approval.',
            'data'    => $product->load('category:id,name'),
        ], 201);
    }

    // ── PUT /api/seller/products/{id} ─────────────────────────────────────────
    public function update(Request $request, int $id)
    {
        $product = Product::withoutGlobalScopes()
            ->where('seller_id', $this->sellerId)
            ->findOrFail($id);

        $validated = $request->validate([
            'category_id' => 'sometimes|integer|exists:categories,id',
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'sometimes|numeric|min:0',
            'stock'       => 'sometimes|integer|min:0',
            'is_active'   => 'sometimes|boolean',
        ]);

        $product->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data'    => $product->load('category:id,name'),
        ]);
    }

    // ── DELETE /api/seller/products/{id} ──────────────────────────────────────
    public function destroy(int $id)
    {
        $product = Product::withoutGlobalScopes()
            ->where('seller_id', $this->sellerId)
            ->findOrFail($id);

        $product->delete(); // Soft delete via SoftDeletes trait on Product model

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ]);
    }
}