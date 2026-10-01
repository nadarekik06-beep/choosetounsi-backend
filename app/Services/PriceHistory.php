<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Selling-price timeline, used to stop fake discounts (raise the price, then
 * "discount" it): a discount's crossed-out price is the lowest price the item
 * had during the last 30 days, never the freshly raised one.
 *
 * Rows are written automatically by Product / ProductVariant `saved` hooks:
 *   variant_id NULL → the product's base price
 *   variant_id set  → that variant's effective price (override, or base when none)
 */
class PriceHistory
{
    public const WINDOW_DAYS = 30;

    /** @var array<string, float> lowest-price lookups for this request */
    private static array $memo = [];

    /**
     * Lowest price over the last 30 days, including the current price and the
     * price that was already active when the window opened.
     */
    public static function lowest(int $productId, ?int $variantId, float $currentPrice): float
    {
        $key = "{$productId}:" . ($variantId ?? 'p') . ':' . $currentPrice;
        if (isset(self::$memo[$key])) return self::$memo[$key];

        $since = now()->subDays(self::WINDOW_DAYS);
        $scope = fn($q) => $q->where('product_id', $productId)
            ->when($variantId, fn($q) => $q->where('variant_id', $variantId), fn($q) => $q->whereNull('variant_id'));

        $inWindow = ProductPriceHistory::query()->where($scope)->where('changed_at', '>=', $since)->min('price');
        $atStart  = ProductPriceHistory::query()->where($scope)->where('changed_at', '<', $since)
            ->orderByDesc('changed_at')->orderByDesc('id')->value('price');

        $lowest = $currentPrice;
        foreach ([$inWindow, $atStart] as $p) {
            if ($p !== null && (float) $p > 0) $lowest = min($lowest, (float) $p);
        }

        return self::$memo[$key] = round($lowest, 3);
    }

    /**
     * lowest() for many products' base prices in two queries.
     *
     * @param  array<int, float> $currentPrices product id => current base price
     * @return array<int, float> product id => lowest 30-day price
     */
    public static function lowestMany(array $currentPrices): array
    {
        $out = [];
        $ids = [];
        foreach ($currentPrices as $id => $price) {
            $key = "{$id}:p:" . (float) $price;
            if (isset(self::$memo[$key])) $out[$id] = self::$memo[$key];
            else $ids[] = (int) $id;
        }
        if (!$ids) return $out;

        $since = now()->subDays(self::WINDOW_DAYS);
        $base  = fn() => ProductPriceHistory::query()->whereIn('product_id', $ids)->whereNull('variant_id')->where('price', '>', 0);

        $inWindow = $base()->where('changed_at', '>=', $since)
            ->groupBy('product_id')->selectRaw('product_id, MIN(price) AS price')->pluck('price', 'product_id');

        // Price already active when the window opened: each product's last row before it
        $atStart = [];
        foreach ($base()->where('changed_at', '<', $since)->orderByDesc('changed_at')->orderByDesc('id')
                     ->get(['product_id', 'price']) as $row) {
            $atStart[$row->product_id] ??= (float) $row->price;
        }

        foreach ($ids as $id) {
            $lowest = (float) $currentPrices[$id];
            foreach ([$inWindow[$id] ?? null, $atStart[$id] ?? null] as $p) {
                if ($p !== null) $lowest = min($lowest, (float) $p);
            }
            $out[$id] = self::$memo["{$id}:p:" . (float) $currentPrices[$id]] = round($lowest, 3);
        }
        return $out;
    }

    /** Lowest 30-day price of a product's base price, or of one variant. */
    public static function lowestFor(Product $product, ?ProductVariant $variant = null): float
    {
        return $variant
            ? self::lowest($product->id, $variant->id, (float) ($variant->price_override ?? $product->price))
            : self::lowest($product->id, null, (float) $product->price);
    }

    /** Called when a product is created or its base price changes. */
    public static function productPriceChanged(Product $product, bool $created): void
    {
        self::write($product->id, null, (float) $product->price);

        // Variants without their own price follow the base price
        if (!$created) {
            $product->variants()->withoutGlobalScopes()->whereNull('price_override')->pluck('id')
                ->each(fn($vid) => self::write($product->id, $vid, (float) $product->price));
        }
    }

    /** Called when a variant is created or its override changes. */
    public static function variantPriceChanged(ProductVariant $variant): void
    {
        $base = $variant->price_override ?? DB::table('products')->where('id', $variant->product_id)->value('price');
        if ($base === null) return;
        self::write($variant->product_id, $variant->id, (float) $base);
    }

    private static function write(int $productId, ?int $variantId, float $price): void
    {
        $user = auth()->user();
        ProductPriceHistory::create([
            'product_id' => $productId,
            'variant_id' => $variantId,
            'price'      => round($price, 3),
            'source'     => $user ? ($user->role === 'admin' ? 'admin' : 'seller') : 'system',
            'changed_at' => now(),
        ]);
        self::$memo = [];
    }

    /** For tests / long-running workers. */
    public static function flush(): void
    {
        self::$memo = [];
    }
}
