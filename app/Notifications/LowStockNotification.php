<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * LowStockNotification
 *
 * Sent to the SELLER when a product or variant's stock drops to or below
 * the configured low-stock threshold.
 *
 * Shape matches the existing NotificationBell.tsx contract:
 *   data.title, data.body, data.icon, data.action, data.link
 *
 * Usage:
 *   $seller->notify(new LowStockNotification($product, $variant, $stock, $threshold));
 *   $seller->notify(new LowStockNotification($product, null, $stock, $threshold)); // simple product
 */
class LowStockNotification extends Notification
{
    use Queueable;

    public function __construct(
        private Product        $product,
        private ?ProductVariant $variant,
        private int            $stock,
        private int            $threshold,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $productName  = $this->product->name;
        $variantLabel = $this->resolveVariantLabel();

        // e.g. "Sneakers (Red / M)" or just "Sneakers"
        $fullName = $variantLabel
            ? "{$productName} ({$variantLabel})"
            : $productName;

        $body = "Only {$this->stock} unit" . ($this->stock === 1 ? '' : 's') . " left in stock. "
              . "Restock soon to avoid losing sales.";

        return [
            // ── Fields consumed by NotificationBell.tsx ───────────────────
            'type'   => 'low_stock',
            'action' => 'low_stock',
            'title'  => "⚠️ Low Stock: {$fullName}",
            'body'   => $body,
            'icon'   => 'package-x',
            'link'   => "/seller/products/{$this->product->id}",

            // ── Extra context (available in notification detail views) ─────
            'product_id'   => $this->product->id,
            'variant_id'   => $this->variant?->id,
            'variant_label'=> $variantLabel,
            'stock'        => $this->stock,
            'threshold'    => $this->threshold,
            'created_at'   => now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Resolve human-readable variant label (e.g. "Red / M").
     * Returns null for simple products (no variants).
     */
    private function resolveVariantLabel(): ?string
    {
        if (!$this->variant) return null;

        // Use pre-loaded attributeOptions if available
        if ($this->variant->relationLoaded('attributeOptions')) {
            return $this->variant->attributeOptions->pluck('value')->join(' / ');
        }

        // Lazy-load as fallback
        return $this->variant->attributeOptions()->pluck('value')->join(' / ') ?: null;
    }
}