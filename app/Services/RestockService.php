<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RestockService
 *
 * Handles DIRECT stock updates (no admin approval required).
 * Only stock-related fields are allowed — any price/title/desc change
 * must go through the ProductUpdateRequest flow.
 *
 * Rules enforced here:
 *   - Seller must own the product
 *   - Only stock values are written
 *   - Variants: can update existing stock + add brand-new variants
 *   - Variants: CANNOT change option_ids, price_override, sku of existing rows
 *     (structural changes require update request)
 *   - After any change, syncActiveStatusFromVariants() is called
 */
class RestockService
{
    /**
     * Restock a simple product (no variants).
     *
     * @param  Product  $product  Already ownership-verified by controller
     * @param  int      $stock    New stock value (>= 0)
     * @return Product  Fresh instance after update
     */
    public function restockSimple(Product $product, int $stock): Product
    {
        $product->update(['stock' => $stock]);

        Log::info('[Restock] Simple product restocked', [
            'product_id' => $product->id,
            'old_stock'  => $product->getOriginal('stock'),
            'new_stock'  => $stock,
        ]);

        return $product->fresh();
    }

    /**
     * Restock a product that has (or will have) variants.
     *
     * $variantsData format:
     * [
     *   // Update existing variant stock:
     *   ['id' => 42,  'stock' => 15],
     *
     *   // Add a new variant (no id — will be created):
     *   ['option_ids' => [3, 7], 'stock' => 10, 'price_override' => null, 'sku' => null],
     * ]
     *
     * Structural changes on existing variants (option_ids, price_override, sku)
     * are silently ignored — only stock is updated for existing rows.
     * New variants (no id) get all their fields set.
     *
     * @param  Product  $product
     * @param  array    $variantsData
     * @return Product  Fresh instance after update
     */
    public function restockWithVariants(Product $product, array $variantsData): Product
    {
        DB::transaction(function () use ($product, $variantsData) {
            foreach ($variantsData as $row) {
                if (!is_array($row)) continue;

                $existingId = isset($row['id']) && $row['id'] ? (int) $row['id'] : null;

                if ($existingId) {
                    // ── UPDATE existing variant: stock only ─────────────────
                    $variant = ProductVariant::where('product_id', $product->id)
                        ->find($existingId);

                    if (!$variant) {
                        Log::warning('[Restock] Variant not found or wrong product', [
                            'product_id' => $product->id,
                            'variant_id' => $existingId,
                        ]);
                        continue;
                    }

                    $oldStock = $variant->stock;
                    $variant->update(['stock' => (int) ($row['stock'] ?? $variant->stock)]);

                    Log::info('[Restock] Variant stock updated', [
                        'variant_id' => $variant->id,
                        'old_stock'  => $oldStock,
                        'new_stock'  => $variant->stock,
                    ]);
                } else {
                    // ── CREATE new variant ──────────────────────────────────
                    $optionIds = array_values(array_filter(
                        array_map('intval', (array) ($row['option_ids'] ?? [])),
                        fn($id) => $id > 0
                    ));

                    if (empty($optionIds)) {
                        Log::warning('[Restock] New variant skipped — no option_ids', ['row' => $row]);
                        continue;
                    }

                    $variant = ProductVariant::create([
                        'product_id'     => $product->id,
                        'stock'          => (int) ($row['stock'] ?? 0),
                        'price_override' => isset($row['price_override']) && $row['price_override'] !== ''
                            ? (float) $row['price_override'] : null,
                        'sku'            => !empty($row['sku']) ? (string) $row['sku'] : null,
                        'is_active'      => (bool) ($row['is_active'] ?? true),
                    ]);

                    $variant->attributeOptions()->sync($optionIds);

                    Log::info('[Restock] New variant created', [
                        'product_id' => $product->id,
                        'variant_id' => $variant->id,
                        'option_ids' => $optionIds,
                        'stock'      => $variant->stock,
                    ]);
                }
            }

            // Re-derive product active status from variant state
            $product->fresh()->syncActiveStatusFromVariants();

            // Sync top-level product stock to sum of variants
            $totalVariantStock = $product->variants()->sum('stock');
            $product->update(['stock' => $totalVariantStock]);
        });

        return $product->fresh(['variants.attributeOptions.attribute']);
    }
}