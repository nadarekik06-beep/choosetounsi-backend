<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with([
            'seller:id,name,email',
            'category:id,name',
            'primaryImage',
        ]);

        $status = $request->query('status', 'pending');
        if ($status === 'pending')       $query->where('is_approved', false);
        elseif ($status === 'approved')  $query->where('is_approved', true)->where('is_active', true);
        elseif ($status === 'disabled')  $query->where('is_approved', true)->where('is_active', false);

        if ($search = $request->query('search'))
            $query->where('name', 'like', "%{$search}%");

        if ($categoryId = $request->query('category_id'))
            $query->where('category_id', $categoryId);

        if ($sellerId = $request->query('seller_id'))
            $query->where('seller_id', $sellerId);

        $products = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 15));

        $products->getCollection()->transform(function ($product) {
            $product->status = $this->deriveStatus($product);
            return $product;
        });

        return response()->json(['success' => true, 'data' => $products]);
    }

    public function show($id)
    {
        $product = Product::with([
            'seller:id,name,email',
            'category:id,name',
            'images',
        ])->findOrFail($id);

        $product->status = $this->deriveStatus($product);

        return response()->json(['success' => true, 'data' => $product]);
    }

    /**
     * PUT /api/admin/products/{id}
     * Admin updates a product's details
     */
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'name'              => 'sometimes|required|string|max:255',
            'description'       => 'nullable|string',
            'short_description' => 'nullable|string|max:500',
            'price'             => 'sometimes|required|numeric|min:0',
            'stock'             => 'sometimes|required|integer|min:0',
            'category_id'       => 'sometimes|required|exists:categories,id',
            'is_active'         => 'sometimes|boolean',
            'is_approved'       => 'sometimes|boolean',
            'featured'          => 'sometimes|boolean',
        ]);

        $product->update($validated);
        $product->load(['seller:id,name,email', 'category:id,name', 'images']);
        $product->status = $this->deriveStatus($product);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data'    => $product,
        ]);
    }

    public function approve($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['is_approved' => true, 'is_active' => true]);
        return response()->json(['success' => true, 'message' => 'Product approved.']);
    }

    public function disable($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['is_active' => false]);
        return response()->json(['success' => true, 'message' => 'Product disabled.']);
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();
        return response()->json(['success' => true, 'message' => 'Product deleted.']);
    }

    private function deriveStatus(Product $product): string
    {
        if (!$product->is_approved) return 'pending';
        if (!$product->is_active)   return 'disabled';
        return 'approved';
    }
}