<?php
// app/Http/Controllers/Api/Seller/ProductUpdateRequestController.php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductUpdateRequest;
use App\Models\User;
use App\Notifications\ProductUpdateRequestNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ProductUpdateRequestController extends Controller
{
    /**
     * List the current seller's requests.
     * GET /api/seller/products/{id}/update-requests
     */
    public function index(Request $request, int $productId)
    {
        $seller  = $request->user();
        $product = $seller->products()->findOrFail($productId);

        $requests = ProductUpdateRequest::where('product_id', $product->id)
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json(['success' => true, 'data' => $requests]);
    }

    /**
     * Submit a new update request.
     * POST /api/seller/products/{id}/request-update
     */
    public function store(Request $request, int $productId)
    {
        $seller  = $request->user();
        $product = $seller->products()->findOrFail($productId);

        // Only locked (approved) products need the request flow
        if (!$product->is_approved) {
            return response()->json([
                'success' => false,
                'message' => 'This product is not yet approved. You can edit it directly.',
            ], 422);
        }

        // Prevent duplicate pending requests
        $existing = ProductUpdateRequest::where('product_id', $product->id)
            ->where('seller_id', $seller->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending update request for this product.',
                'data'    => $existing,
            ], 422);
        }

        $validated = $request->validate([
            'price'          => 'sometimes|numeric|min:0',
            'stock'          => 'sometimes|integer|min:0',
            'category_id'    => 'sometimes|exists:categories,id',
            'subcategory_id' => 'sometimes|nullable|exists:subcategories,id',
            'variants'       => 'sometimes|array',
            'variants.*.id'             => 'sometimes|integer',
            'variants.*.option_ids'     => 'sometimes|array',
            'variants.*.stock'          => 'sometimes|integer|min:0',
            'variants.*.price_override' => 'sometimes|nullable|numeric|min:0',
            'variants.*.sku'            => 'sometimes|nullable|string|max:100',
            'variants.*.is_active'      => 'sometimes|boolean',
            'note'           => 'sometimes|nullable|string|max:1000',
        ]);

        // Build only the fields that were actually sent
        $proposedData = [];
        $criticalFields = ['price', 'stock', 'category_id', 'subcategory_id', 'variants'];

        foreach ($criticalFields as $field) {
            if ($request->has($field)) {
                $proposedData[$field] = $validated[$field] ?? $request->input($field);
            }
        }

        if (empty($proposedData)) {
            return response()->json([
                'success' => false,
                'message' => 'No changes were submitted.',
            ], 422);
        }

        if (isset($validated['note'])) {
            $proposedData['_note'] = $validated['note'];
        }

        $updateRequest = ProductUpdateRequest::create([
            'product_id'    => $product->id,
            'seller_id'     => $seller->id,
            'proposed_data' => $proposedData,
            'status'        => 'pending',
        ]);

        // Notify all admins
        $this->notifyAdmins($updateRequest, $product, $seller);

        return response()->json([
            'success' => true,
            'message' => 'Your update request has been submitted and is pending admin review.',
            'data'    => $updateRequest,
        ], 201);
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private function notifyAdmins(ProductUpdateRequest $updateRequest, Product $product, User $seller): void
    {
        try {
            $admins = User::where('role', 'admin')->where('is_active', true)->get();
            if ($admins->isEmpty()) return;

            Notification::send($admins, new ProductUpdateRequestNotification(
                'submitted',
                $updateRequest->id,
                $product->id,
                $product->name,
                $seller->name,
            ));
        } catch (\Throwable $e) {
            Log::error('[ProductUpdateRequest] Admin notification failed: ' . $e->getMessage());
        }
    }
}