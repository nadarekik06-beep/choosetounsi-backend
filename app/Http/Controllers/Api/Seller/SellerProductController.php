<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SellerProductController extends Controller
{
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

        // Create product
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
        ]);

        // Upload images
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products', 'public');
                
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_path' => $path,
                    'order' => $index,
                    'is_primary' => $index === 0, // First image is primary
                ]);
            }
        }

        return response()->json([
            'message' => 'Product created successfully! Awaiting admin approval.',
            'product' => $product->load('images', 'category'),
        ], 201);
    }

    /**
     * Add more images to existing product
     */
    public function uploadImages(Request $request, Product $product)
    {
        // Verify ownership
        if ($product->seller_id !== $request->user()->id) {
            abort(403);
        }

        $request->validate([
            'images' => 'required|array|min:1|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $currentCount = $product->images()->count();
        $maxOrder = $product->images()->max('order') ?? -1;

        foreach ($request->file('images') as $index => $image) {
            if ($currentCount + $index >= 5) break; // Max 5 images

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
}