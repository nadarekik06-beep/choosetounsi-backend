<?php
// app/Http/Controllers/Admin/ProductController.php
// FULL REPLACEMENT
// approve()  → notifies seller ✓
// reject()   → notifies seller ✓
// update()   → notifies seller ✓  (NEW)
// destroy()  → notifies seller ✓  (NEW)

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Notifications\ProductReviewedNotification;
use App\Notifications\ProductActionNotification;
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
        if ($status === 'pending')      $query->where('is_approved', false);
        elseif ($status === 'approved') $query->where('is_approved', true)->where('is_active', true);
        elseif ($status === 'disabled') $query->where('is_approved', true)->where('is_active', false);

        if ($search = $request->query('search'))
            $query->where('name', 'like', '%' . $search . '%');

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
     * Admin edits product details — notifies the seller
     */
    public function update(Request $request, $id)
    {
        $product = Product::with('seller')->findOrFail($id);

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

        // Notify the seller that admin edited their product
        if ($product->seller) {
            $product->seller->notify(
                new ProductActionNotification(
                    'updated',
                    $product->id,
                    $product->name,
                    'Admin',
                    0
                )
            );
        }

        $product->load(['seller:id,name,email', 'category:id,name', 'images']);
        $product->status = $this->deriveStatus($product);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data'    => $product,
        ]);
    }

    /**
     * PATCH /api/admin/products/{id}/approve
     * Notifies the seller
     */
    public function approve($id)
    {
        $product = Product::with('seller')->findOrFail($id);
        $product->update(['is_approved' => true, 'is_active' => true]);

        if ($product->seller) {
            $product->seller->notify(
                new ProductReviewedNotification(
                    'approved',
                    $product->id,
                    $product->name
                )
            );
        }

        return response()->json(['success' => true, 'message' => 'Product approved.']);
    }

    /**
     * PATCH /api/admin/products/{id}/reject
     * Notifies the seller
     */
    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $product = Product::with('seller')->findOrFail($id);
        $product->update(['is_approved' => false, 'is_active' => false]);

        if ($product->seller) {
            $product->seller->notify(
                new ProductReviewedNotification(
                    'rejected',
                    $product->id,
                    $product->name,
                    $request->reason
                )
            );
        }

        return response()->json(['success' => true, 'message' => 'Product rejected.']);
    }

    /**
     * PATCH /api/admin/products/{id}/disable
     */
    public function disable($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['is_active' => false]);
        return response()->json(['success' => true, 'message' => 'Product disabled.']);
    }

    /**
     * DELETE /api/admin/products/{id}
     * Notifies the seller
     */
    public function destroy($id)
    {
        $product = Product::with('seller')->findOrFail($id);

        $productName = $product->name;
        $productId   = $product->id;
        $seller      = $product->seller;

        $product->delete();

        if ($seller) {
            $seller->notify(
                new ProductActionNotification(
                    'deleted',
                    $productId,
                    $productName,
                    'Admin',
                    0
                )
            );
        }

        return response()->json(['success' => true, 'message' => 'Product deleted.']);
    }

    private function deriveStatus(Product $product)
    {
        if (!$product->is_approved) return 'pending';
        if (!$product->is_active)   return 'disabled';
        return 'approved';
    }
}