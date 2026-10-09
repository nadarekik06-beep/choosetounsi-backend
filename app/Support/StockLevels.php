<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Stock level rules shared by the seller/admin product views and the stock alerts.
 *
 *   threshold  products.low_stock_threshold → seller's shop setting → config('stock.low_stock_threshold')
 *   total      a product with variants holds the sum of its ACTIVE variants' stock;
 *              products.stock is kept equal to it (syncProductStock)
 *   state      out (0) · low (≤ threshold) · ok · inactive (variant switched off)
 */
final class StockLevels
{
    public const OUT      = 'out';
    public const LOW      = 'low';
    public const OK       = 'ok';
    public const INACTIVE = 'inactive';

    public static function threshold(Product $product): int
    {
        if ($product->low_stock_threshold !== null) {
            return (int) $product->low_stock_threshold;
        }
        $shop = $product->relationLoaded('seller') ? $product->seller?->stock_alert_threshold : null;
        if ($shop === null && !$product->relationLoaded('seller') && $product->seller_id) {
            $shop = DB::table('users')->where('id', $product->seller_id)->value('stock_alert_threshold');
        }
        return (int) ($shop ?? config('stock.low_stock_threshold', 2));
    }

    public static function state(int $stock, int $threshold, bool $active = true): string
    {
        if (!$active)              return self::INACTIVE;
        if ($stock <= 0)           return self::OUT;
        if ($stock <= $threshold)  return self::LOW;
        return self::OK;
    }

    /**
     * products.stock of a product with variants = its active variants' stock.
     * Raw query: runs from the raw order-stock paths and never re-fires observers.
     */
    public static function syncProductStock(int $productId): void
    {
        $variants = DB::table('product_variants')->where('product_id', $productId);
        if (!(clone $variants)->exists()) {
            return;
        }
        DB::table('products')->where('id', $productId)->update([
            'stock' => (int) (clone $variants)->where('is_active', true)->sum('stock'),
        ]);
    }

    /**
     * Per-variant stock for the product views (seller + admin detail, admin list tooltip).
     * Wants variants.attributeOptions.attribute loaded; seller loaded avoids one query.
     *
     * @return array{has_variants: bool, total: int, threshold: int, threshold_source: string,
     *               low_count: int, out_count: int, inactive_count: int, variants: array}
     */
    public static function breakdown(Product $product): array
    {
        $threshold = self::threshold($product);
        $variants  = $product->relationLoaded('variants') ? $product->variants : $product->variants()->get();
        $basePrice = (float) $product->price;

        $rows = $variants->sortBy('id')->values()->map(function (ProductVariant $v) use ($threshold, $basePrice) {
            $options = $v->attributeOptions
                ->sortBy(fn($o) => [optional($o->attribute)->slug === 'color' ? 0 : 1, optional($o->attribute)->slug, $o->id])
                ->values()
                ->map(fn($o) => [
                    'attribute' => optional($o->attribute)->slug,
                    'value'     => $o->value,
                    'color_hex' => $o->color_hex,
                ]);
            $price = $v->price_override !== null ? (float) $v->price_override : null;

            return [
                'id'        => $v->id,
                'label'     => $options->pluck('value')->filter()->join(' / '),
                'options'   => $options->all(),
                'stock'     => (int) $v->stock,
                'price'     => $price !== null && abs($price - $basePrice) > 0.0005 ? $price : null,
                'sku'       => $v->sku ?: null,
                'is_active' => (bool) $v->is_active,
                'state'     => self::state((int) $v->stock, $threshold, (bool) $v->is_active),
            ];
        });

        $hasVariants = $rows->isNotEmpty();
        $total       = $hasVariants ? (int) $rows->where('is_active', true)->sum('stock') : (int) $product->stock;

        return [
            'has_variants'     => $hasVariants,
            'total'            => $total,
            'state'            => self::state($total, $threshold),
            'threshold'        => $threshold,
            'threshold_source' => $product->low_stock_threshold !== null ? 'product' : 'shop',
            'low_count'        => $rows->where('state', self::LOW)->count(),
            'out_count'        => $rows->where('state', self::OUT)->count(),
            'inactive_count'   => $rows->where('state', self::INACTIVE)->count(),
            'variants'         => $rows->all(),
        ];
    }
}
