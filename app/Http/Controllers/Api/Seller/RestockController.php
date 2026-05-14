<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\RestockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Api\Seller\SellerForecastController;

/**
 * RestockController
 *
 * POST /api/seller/products/{id}/restock
 *
 * Allows sellers to update stock DIRECTLY — no admin approval required.
 * This endpoint is intentionally limited to stock-only changes:
 *
 *   - Simple products: update `stock` field
 *   - Variant products: update per-variant stock + optionally add new variants
 *
 * Any structural or pricing change (name, price, category, variant option_ids)
 * must go through the ProductUpdateRequest flow.
 *
 * Security:
 *   - auth:sanctum middleware (applied at route level)
 *   - Seller ownership enforced in ensureOwner()
 *   - Approved products CAN be restocked (this is the whole point)
 *   - Unapproved products CAN also be restocked (seller building catalog)
 */
class RestockController extends Controller
{
    public function __construct(private RestockService $restockService)
    {
    }

    /**
     * POST /api/seller/products/{id}/restock
     */
    public function restock(Request $request, int $id)
    {
        $seller  = $request->user();
        $product = $seller->products()->findOrFail($id);

        // Determine whether this is a variant product
        $hasVariants = $product->variants()->exists();

        if ($hasVariants) {
            return $this->restockVariantProduct($request, $product);
        }
        SellerForecastController::clearForecastCache($product->id, auth()->id());

        return $this->restockSimpleProduct($request, $product);
    }

    // ── Private handlers ────────────────────────────────────────────────────

    /**
     * Simple product restock — just update the stock field.
     */
    private function restockSimpleProduct(Request $request, Product $product)
    {
        $request->validate([
            'stock' => 'required|integer|min:0|max:99999',
        ]);

        try {
            $updated = $this->restockService->restockSimple($product, (int) $request->stock);

            return response()->json([
                'success' => true,
                'message' => "Stock updated to {$updated->stock} units.",
                'data'    => [
                    'id'           => $updated->id,
                    'stock'        => $updated->stock,
                    'is_active'    => $updated->is_active,
                    'has_variants' => false,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Restock] Simple restock failed', [
                'product_id' => $product->id,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Restock failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Variant product restock.
     * Expects `variants` array with per-variant stock updates + optional new variants.
     */
    private function restockVariantProduct(Request $request, Product $product)
    {
        $request->validate([
            'variants'                  => 'required|array|min:1',
            'variants.*.id'             => 'sometimes|nullable|integer',
            'variants.*.stock'          => 'required|integer|min:0|max:99999',
            // New variant fields (only used when id is absent)
            'variants.*.option_ids'     => 'sometimes|array',
            'variants.*.option_ids.*'   => 'integer|min:1',
            'variants.*.price_override' => 'sometimes|nullable|numeric|min:0',
            'variants.*.sku'            => 'sometimes|nullable|string|max:100',
            'variants.*.is_active'      => 'sometimes|boolean',
        ]);

        try {
            $updated = $this->restockService->restockWithVariants($product, $request->variants);

            $variantSummary = $updated->variants->map(fn($v) => [
                'id'     => $v->id,
                'label'  => $v->label,
                'stock'  => $v->stock,
            ])->toArray();

            $totalStock = $updated->variants->sum('stock');

            return response()->json([
                'success' => true,
                'message' => "Variant stock updated. Total: {$totalStock} units.",
                'data'    => [
                    'id'            => $updated->id,
                    'stock'         => $updated->stock,
                    'is_active'     => $updated->is_active,
                    'has_variants'  => true,
                    'variant_stock' => $totalStock,
                    'variants'      => $variantSummary,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Restock] Variant restock failed', [
                'product_id' => $product->id,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Restock failed. Please try again.',
            ], 500);
        }
    }
}