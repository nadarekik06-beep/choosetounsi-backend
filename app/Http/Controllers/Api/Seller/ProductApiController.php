<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductApiController extends Controller
{
    /**
     * Get seller's products with filters
     */
    public function index(Request $request)
    {
        $seller = $request->user();

        $query = $seller->products()->with(['category', 'images']);

        // Filter by approval status
        if ($request->has('status')) {
            if ($request->status === 'approved') {
                $query->where('is_approved', true);
            } elseif ($request->status === 'pending') {
                $query->where('is_approved', false);
            }
        }

        // Filter by active status
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort', 'created_at');
        $sortOrder = $request->get('order', 'desc');

        $query->orderBy($sortBy, $sortOrder);

        return response()->json($query->paginate(20));
    }

    /**
     * Get seller statistics
     */
    public function statistics(Request $request)
    {
        $seller = $request->user();

        return response()->json([
            'total_products'    => $seller->products()->count(),
            'approved_products' => $seller->products()->where('is_approved', true)->count(),
            'pending_products'  => $seller->products()->where('is_approved', false)->count(),
            'active_products'   => $seller->products()->where('is_active', true)->count(),
            'total_stock'       => (int) $seller->products()->sum('stock'),
            'out_of_stock'      => $seller->products()->where('stock', 0)->count(),
            'total_views'       => (int) $seller->products()->sum('views'),
        ]);
    }

    /**
     * Create product with multiple images
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'description' => 'required|string|min:50',
            'short_description' => 'nullable|string|max:200',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'sku' => 'nullable|string|unique:products,sku',
            'images' => 'required|array|min:1|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $product = $request->user()->products()->create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'category_id' => $validated['category_id'],
            'description' => $validated['description'],
            'short_description' => $validated['short_description'] ?? null,
            'price' => $validated['price'],
            'stock' => $validated['stock'],
            'sku' => $validated['sku'] ?? null,
            'is_approved' => false,
            'is_active' => false,
            'featured' => false,
            'views' => 0,
        ]);

        foreach ($request->file('images') as $index => $image) {
            $path = $image->store('products', 'public');

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $path,
                'order' => $index,
                'is_primary' => $index === 0,
            ]);
        }

        return response()->json([
            'message' => 'Product created successfully! It will be reviewed by admin.',
            'product' => $product->load('images', 'category'),
        ], 201);
    }

    /**
     * Get single product
     */
    public function show(Request $request, Product $product)
    {
        $this->ensureOwner($request, $product);

        return response()->json([
            'product' => $product->load(['category', 'images']),
        ]);
    }

    /**
     * Update product
     */
    public function update(Request $request, Product $product)
    {
        $this->ensureOwner($request, $product);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'description' => 'required|string|min:50',
            'short_description' => 'nullable|string|max:200',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'sku' => 'nullable|string|unique:products,sku,' . $product->id,
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product->load('images', 'category'),
        ]);
    }

    /**
     * Delete product
     */
    public function destroy(Request $request, Product $product)
    {
        $this->ensureOwner($request, $product);

        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->image_path);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

    /**
     * Upload additional images
     */
    public function uploadImages(Request $request, Product $product)
    {
        $this->ensureOwner($request, $product);

        $request->validate([
            'images' => 'required|array|min:1|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $currentCount = $product->images()->count();

        if ($currentCount + count($request->file('images')) > 5) {
            return response()->json([
                'message' => 'Cannot upload more than 5 images per product.',
            ], 400);
        }

        $maxOrder = $product->images()->max('order') ?? -1;

        foreach ($request->file('images') as $index => $image) {
            $path = $image->store('products', 'public');

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $path,
                'order' => $maxOrder + $index + 1,
                'is_primary' => false,
            ]);
        }

        return response()->json([
            'message' => 'Images uploaded successfully.',
            'product' => $product->fresh()->load('images'),
        ]);
    }

    /**
     * Delete product image
     */
    public function deleteImage(Request $request, Product $product, ProductImage $image)
    {
        $this->ensureOwner($request, $product);

        if ($image->product_id !== $product->id) {
            return response()->json([
                'message' => 'Image does not belong to this product.',
            ], 403);
        }

        if ($product->images()->count() <= 1) {
            return response()->json([
                'message' => 'Cannot delete the last image.',
            ], 400);
        }

        Storage::disk('public')->delete($image->image_path);

        $wasPrimary = $image->is_primary;
        $image->delete();

        if ($wasPrimary) {
            $firstImage = $product->images()->first();
            if ($firstImage) {
                $firstImage->update(['is_primary' => true]);
            }
        }

        return response()->json([
            'message' => 'Image deleted successfully.',
            'product' => $product->fresh()->load('images'),
        ]);
    }

    /**
     * Ensure seller owns the product
     */
    private function ensureOwner(Request $request, Product $product)
    {
        if ($product->seller_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this product.');
        }
    }
}
