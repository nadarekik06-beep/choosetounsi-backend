<?php
// app/Http/Controllers/Admin/ProductController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Notifications\ProductReviewedNotification;
use App\Notifications\ProductActionNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

            $product->primary_image_url = $product->primaryImage
                ? Storage::disk('public')->url($product->primaryImage->image_path)
                : null;

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
            'primaryImage',
        ])->findOrFail($id);

        $product->status = $this->deriveStatus($product);

        $product->primary_image_url = $product->primaryImage
            ? Storage::disk('public')->url($product->primaryImage->image_path)
            : null;

        $product->images->each(function ($image) {
            $image->url = Storage::disk('public')->url($image->image_path);
        });

        return response()->json(['success' => true, 'data' => $product]);
    }

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

        // ✅ Notify the seller that admin edited their product
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

        // ✅ Load relations including primary image
        $product->load(['seller:id,name,email', 'category:id,name', 'images', 'primaryImage']);

        $product->status = $this->deriveStatus($product);

        $product->primary_image_url = $product->primaryImage
            ? Storage::disk('public')->url($product->primaryImage->image_path)
            : null;

        $product->images->each(function ($image) {
            $image->url = Storage::disk('public')->url($image->image_path);
        });

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data'    => $product,
        ]);
    }

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

    public function disable($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['is_active' => false]);

        return response()->json(['success' => true, 'message' => 'Product disabled.']);
    }

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