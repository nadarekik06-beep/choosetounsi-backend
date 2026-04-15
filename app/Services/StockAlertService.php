<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Notifications\LowStockNotification;
use App\Notifications\OutOfStockNotification;
use Illuminate\Support\Facades\Log;

/**
 * StockAlertService
 *
 * Single responsibility: decide whether to fire a stock notification
 * and dispatch it to the seller.
 *
 * ── Design principles ──────────────────────────────────────────────────────
 *
 * 1. THRESHOLD CROSSING — not level detection
 *    A notification fires only when stock CROSSES a threshold, not every
 *    time stock is updated while already below it. This prevents spam when
 *    a seller updates stock that is already low.
 *
 * 2. COOLDOWN DEDUPLICATION
 *    Even on a crossing, we enforce a configurable cooldown window
 *    (default 24h). If a notification of the same type was sent within
 *    the window, we skip.
 *
 * 3. RESET ON RECOVERY
 *    When stock recovers above the threshold, the notified_at timestamps
 *    are cleared. This allows fresh notifications on the NEXT crossing.
 *
 * 4. VARIANT-AWARE
 *    The service handles both simple products (stock on products table)
 *    and variant products (stock on product_variants table). For variant
 *    products, the product-level notification is derived from variant
 *    aggregate stock.
 *
 * 5. SILENT FAILURE
 *    All exceptions are caught and logged — a notification failure must
 *    NEVER break the checkout flow.
 *
 * ── Call sites ─────────────────────────────────────────────────────────────
 *
 *   After variant decrement (CheckoutController):
 *     $this->stockAlertService->checkVariant($variant->fresh(), $product);
 *
 *   After product decrement (CheckoutController):
 *     $this->stockAlertService->checkProduct($product->fresh());
 *
 *   From ProductVariantObserver (seller dashboard edits):
 *     $this->stockAlertService->checkVariant($variant, $variant->product);
 *
 *   From ProductObserver (simple product edits):
 *     $this->stockAlertService->checkProduct($product);
 */
class StockAlertService
{
    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Check a simple (non-variant) product after a stock change.
     *
     * Call with the FRESHLY LOADED model (after decrement/save) so the
     * stock value reflects the current DB state.
     */
    public function checkProduct(Product $product): void
    {
        try {
            $seller    = $product->seller;
            $threshold = $this->threshold($product);
            $stock     = (int) $product->stock;

            if (!$seller) return;

            if ($stock === 0) {
                $this->maybeNotifyOutOfStock(
                    notifiable:  $seller,
                    product:     $product,
                    variant:     null,
                    getNotifiedAt: fn() => $product->last_out_of_stock_notified_at,
                    setNotifiedAt: fn() => $product->updateQuietly(['last_out_of_stock_notified_at' => now()]),
                    clearLowAt:    fn() => $product->updateQuietly(['last_low_stock_notified_at' => null]),
                );
                return;
            }

            if ($stock <= $threshold) {
                $this->maybeNotifyLowStock(
                    notifiable:  $seller,
                    product:     $product,
                    variant:     null,
                    stock:       $stock,
                    threshold:   $threshold,
                    getNotifiedAt: fn() => $product->last_low_stock_notified_at,
                    setNotifiedAt: fn() => $product->updateQuietly(['last_low_stock_notified_at' => now()]),
                );
                return;
            }

            // Stock recovered above threshold — reset flags so next crossing
            // triggers fresh notifications.
            $this->resetFlags($product, null);

        } catch (\Throwable $e) {
            Log::error('[StockAlertService::checkProduct] ' . $e->getMessage(), [
                'product_id' => $product->id,
            ]);
        }
    }

    /**
     * Check a product variant after a stock change.
     *
     * Call with the FRESHLY LOADED variant so the stock value is current.
     * $product is passed to avoid an extra DB query (it's already loaded
     * at the call site).
     */
    public function checkVariant(ProductVariant $variant, Product $product): void
    {
        try {
            $seller    = $product->seller ?? $variant->product?->seller;
            $threshold = $this->threshold($product);
            $stock     = (int) $variant->stock;

            if (!$seller) return;

            if ($stock === 0) {
                $this->maybeNotifyOutOfStock(
                    notifiable:  $seller,
                    product:     $product,
                    variant:     $variant,
                    getNotifiedAt: fn() => $variant->last_out_of_stock_notified_at,
                    setNotifiedAt: fn() => $variant->updateQuietly(['last_out_of_stock_notified_at' => now()]),
                    clearLowAt:    fn() => $variant->updateQuietly(['last_low_stock_notified_at' => null]),
                );
                return;
            }

            if ($stock <= $threshold) {
                $this->maybeNotifyLowStock(
                    notifiable:  $seller,
                    product:     $product,
                    variant:     $variant,
                    stock:       $stock,
                    threshold:   $threshold,
                    getNotifiedAt: fn() => $variant->last_low_stock_notified_at,
                    setNotifiedAt: fn() => $variant->updateQuietly(['last_low_stock_notified_at' => now()]),
                );
                return;
            }

            // Stock recovered
            $this->resetFlags(null, $variant);

        } catch (\Throwable $e) {
            Log::error('[StockAlertService::checkVariant] ' . $e->getMessage(), [
                'variant_id' => $variant->id,
                'product_id' => $product->id,
            ]);
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Get the effective low-stock threshold for a product.
     * Per-product override takes precedence over global config.
     */
    private function threshold(Product $product): int
    {
        return $product->low_stock_threshold
            ?? config('stock.low_stock_threshold', 5);
    }

    /**
     * Get the cooldown window in hours.
     */
    private function cooldownHours(): int
    {
        return (int) config('stock.notification_cooldown_hours', 24);
    }

    /**
     * Decide whether to send a LOW STOCK notification.
     *
     * Rules:
     *  1. No prior low-stock notification exists (null timestamp), OR
     *  2. The cooldown window has elapsed since the last one.
     *
     * Note: we do NOT block if an out-of-stock notification was sent
     * (stock could have partially recovered into the low zone).
     */
    private function maybeNotifyLowStock(
        $notifiable,
        Product $product,
        ?ProductVariant $variant,
        int $stock,
        int $threshold,
        callable $getNotifiedAt,
        callable $setNotifiedAt,
    ): void {
        $lastNotifiedAt = $getNotifiedAt();

        if ($lastNotifiedAt !== null) {
            $cooldown = now()->subHours($this->cooldownHours());
            if ($lastNotifiedAt > $cooldown) {
                // Still within cooldown window — skip
                return;
            }
        }

        // Send notification
        $notifiable->notify(new LowStockNotification($product, $variant, $stock, $threshold));

        // Record timestamp
        $setNotifiedAt();
    }

    /**
     * Decide whether to send an OUT OF STOCK notification.
     *
     * Rules:
     *  1. No prior out-of-stock notification exists (null timestamp), OR
     *  2. The cooldown window has elapsed.
     *
     * Additionally clears the low-stock timestamp since out-of-stock
     * supersedes it — the next partial restock should trigger low-stock
     * fresh again.
     */
    private function maybeNotifyOutOfStock(
        $notifiable,
        Product $product,
        ?ProductVariant $variant,
        callable $getNotifiedAt,
        callable $setNotifiedAt,
        callable $clearLowAt,
    ): void {
        $lastNotifiedAt = $getNotifiedAt();

        if ($lastNotifiedAt !== null) {
            $cooldown = now()->subHours($this->cooldownHours());
            if ($lastNotifiedAt > $cooldown) {
                return;
            }
        }

        $notifiable->notify(new OutOfStockNotification($product, $variant));

        $setNotifiedAt();
        $clearLowAt(); // clear low-stock flag — out-of-stock supersedes it
    }

    /**
     * Reset notification flags when stock recovers above threshold.
     *
     * Called when stock > threshold so that the NEXT time stock crosses
     * down, it's treated as a fresh event and will notify again.
     *
     * Uses updateQuietly to avoid triggering observers unnecessarily.
     */
    private function resetFlags(?Product $product, ?ProductVariant $variant): void
    {
        if ($product) {
            // Only reset if at least one flag is currently set
            if ($product->last_low_stock_notified_at || $product->last_out_of_stock_notified_at) {
                $product->updateQuietly([
                    'last_low_stock_notified_at'    => null,
                    'last_out_of_stock_notified_at' => null,
                ]);
            }
        }

        if ($variant) {
            if ($variant->last_low_stock_notified_at || $variant->last_out_of_stock_notified_at) {
                $variant->updateQuietly([
                    'last_low_stock_notified_at'    => null,
                    'last_out_of_stock_notified_at' => null,
                ]);
            }
        }
    }
}