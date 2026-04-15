<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * OutOfStockNotification
 *
 * Sent to the SELLER when a product or variant's stock reaches exactly 0.
 *
 * Uses a stronger visual treatment than LowStockNotification — the action
 * 'out_of_stock' lets the frontend apply a distinct accent color and icon.
 *
 * Usage:
 *   $seller->notify(new OutOfStockNotification($product, $variant));
 *   $seller->notify(new OutOfStockNotification($product, null)); // simple product
 */
class OutOfStockNotification extends Notification
{
    use Queueable;

    public function __construct(
        private Product         $product,
        private ?ProductVariant $variant,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $productName  = $this->product->name;
        $variantLabel = $this->resolveVariantLabel();

        $fullName = $variantLabel
            ? "{$productName} ({$variantLabel})"
            : $productName;

        return [
            // ── Fields consumed by NotificationBell.tsx ───────────────────
            'type'   => 'out_of_stock',
            'action' => 'out_of_stock',
            'title'  => "🚨 Out of Stock: {$fullName}",
            'body'   => "This item is now out of stock. Update your inventory to resume sales.",
            'icon'   => 'package-x',
            'link'   => "/seller/products/{$this->product->id}",

            // ── Extra context ─────────────────────────────────────────────
            'product_id'    => $this->product->id,
            'variant_id'    => $this->variant?->id,
            'variant_label' => $variantLabel,
            'stock'         => 0,
            'created_at'    => now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function resolveVariantLabel(): ?string
    {
        if (!$this->variant) return null;

        if ($this->variant->relationLoaded('attributeOptions')) {
            return $this->variant->attributeOptions->pluck('value')->join(' / ');
        }

        return $this->variant->attributeOptions()->pluck('value')->join(' / ') ?: null;
    }
}