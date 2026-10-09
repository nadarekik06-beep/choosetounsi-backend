<?php

namespace App\Services;

use App\Jobs\FlushStockAlerts;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Notifications\OutOfStockNotification;
use App\Support\StockLevels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Low-stock / out-of-stock alerts to the seller.
 *
 * ── Rules ──────────────────────────────────────────────────────────────────
 *
 * 1. PER VARIANT when the product has variants, otherwise per product.
 *    Threshold: StockLevels::threshold() (product override → shop setting).
 *
 * 2. ONE ALERT PER CROSSING. last_low_stock_notified_at / last_out_of_stock_notified_at
 *    are the "alert sent" flags: set = the item already is in that zone. A sale
 *    only alerts when it claims an empty flag (atomic UPDATE … WHERE flag IS NULL,
 *    so two concurrent orders can't both alert). Flags clear when the stock
 *    goes back above the threshold (resp. above 0).
 *
 * 3. ONLY SALES ALERT. Seller/admin edits, restocks and new products go
 *    through syncFlags(): the flags follow the stock silently, so a product
 *    created or edited at 2 units never alerts.
 *
 * 4. OUT OF STOCK is always sent (its own notification), even with low-stock
 *    alerts switched off. Low-stock alerts follow users.stock_alerts_enabled.
 *
 * 5. GROUPED. Crossings are stored in stock_alert_events; FlushStockAlerts
 *    sends one notification per kind for everything that crossed in the same
 *    order or within config('stock.alert_group_window_minutes').
 *
 * 6. SILENT FAILURE: an alert problem never breaks checkout.
 */
class StockAlertService
{
    public const LOW = 'low';
    public const OUT = 'out';

    // ── Sales ────────────────────────────────────────────────────────────────

    /**
     * Stock just left the shelf for an order (after the commit).
     *
     * @param array<int, array{product_id: int, variant_id: ?int}> $lines
     */
    public function recordSales(array $lines): void
    {
        try {
            $sellers = [];
            $seen    = [];
            foreach ($lines as $line) {
                $key = ($line['variant_id'] ?? 0) . ':' . $line['product_id'];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $product = Product::with('seller')->find($line['product_id']);
                if (!$product || !$product->seller) continue;

                $variant = !empty($line['variant_id']) ? ProductVariant::find($line['variant_id']) : null;
                if (!empty($line['variant_id']) && !$variant) continue;
                if ($variant) StockLevels::syncProductStock($product->id);

                if ($this->evaluateSale($product, $variant)) {
                    $sellers[$product->seller_id] = true;
                }
            }
            foreach (array_keys($sellers) as $sellerId) {
                $this->scheduleFlush((int) $sellerId);
            }
        } catch (\Throwable $e) {
            Log::error('[StockAlertService::recordSales] ' . $e->getMessage());
        }
    }

    /** True when a crossing was queued. */
    private function evaluateSale(Product $product, ?ProductVariant $variant): bool
    {
        $item      = $variant ?? $product;
        $stock     = (int) $item->stock;
        $threshold = StockLevels::threshold($product);
        $seller    = $product->seller;

        if ($stock <= 0) {
            // Out supersedes low: a partial restock into the low zone stays silent
            $this->claim($item, 'last_low_stock_notified_at');
            return $this->claim($item, 'last_out_of_stock_notified_at')
                && $this->queue($seller, $product, $variant, self::OUT, 0, $threshold);
        }

        if ($stock <= $threshold) {
            $this->release($item, 'last_out_of_stock_notified_at');
            return $this->claim($item, 'last_low_stock_notified_at')
                && $seller->stock_alerts_enabled
                && $this->queue($seller, $product, $variant, self::LOW, $stock, $threshold);
        }

        $this->release($item, 'last_low_stock_notified_at');
        $this->release($item, 'last_out_of_stock_notified_at');
        return false;
    }

    // ── Edits / restocks (never alert) ───────────────────────────────────────

    /** The flags follow the current stock without alerting. */
    public function syncFlags(Product $product, ?ProductVariant $variant = null): void
    {
        try {
            $item      = $variant ?? $product;
            $stock     = (int) $item->stock;
            $threshold = StockLevels::threshold($product);

            $stock <= $threshold ? $this->claim($item, 'last_low_stock_notified_at') : $this->release($item, 'last_low_stock_notified_at');
            $stock <= 0          ? $this->claim($item, 'last_out_of_stock_notified_at') : $this->release($item, 'last_out_of_stock_notified_at');
        } catch (\Throwable $e) {
            Log::error('[StockAlertService::syncFlags] ' . $e->getMessage(), ['product_id' => $product->id]);
        }
    }

    /** syncFlags() for a whole product: itself or each of its variants. */
    public function syncProductFlags(Product $product): void
    {
        $variants = $product->variants()->get();
        if ($variants->isEmpty()) {
            $this->syncFlags($product);
            return;
        }
        foreach ($variants as $variant) {
            $this->syncFlags($product, $variant);
        }
    }

    /** Stock given back by raw queries (cancelled order, returned item). */
    public function restocked(int $productId, ?int $variantId): void
    {
        StockLevels::syncProductStock($productId);
        $product = Product::with('seller')->find($productId);
        if (!$product) return;
        $variant = $variantId ? ProductVariant::find($variantId) : null;
        $this->syncFlags($product, $variant);
    }

    /** The shop threshold changed: re-align every flag of the seller, silently. */
    public function syncSeller(User $seller): void
    {
        Product::with('seller')->where('seller_id', $seller->id)->chunkById(100, function ($products) {
            foreach ($products as $product) {
                if ($product->low_stock_threshold === null) {
                    $this->syncProductFlags($product);
                }
            }
        });
    }

    // ── Grouping ─────────────────────────────────────────────────────────────

    /**
     * Send what is pending for a seller: one notification per kind.
     * Called by FlushStockAlerts; rows are claimed under a lock, so two
     * overlapping jobs never send the same crossing twice.
     */
    public function flush(int $sellerId): void
    {
        $seller = User::find($sellerId);
        if (!$seller) return;

        $events = DB::transaction(function () use ($sellerId) {
            $rows = DB::table('stock_alert_events')
                ->where('seller_id', $sellerId)
                ->whereNull('notified_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($rows->isNotEmpty()) {
                DB::table('stock_alert_events')->whereIn('id', $rows->pluck('id'))->update(['notified_at' => now()]);
            }
            return $rows;
        });
        if ($events->isEmpty()) return;

        $withEmail = $seller->stock_alert_channel === 'in_app_email';

        foreach ([self::OUT, self::LOW] as $kind) {
            // Latest state per item (an item can go low then out within the window)
            $items = $events->where('kind', $kind)
                ->groupBy(fn($e) => $e->product_id . ':' . ($e->variant_id ?? 0))
                ->map(fn($group) => $group->last())
                ->values();

            if ($kind === self::LOW) {
                $outKeys = $events->where('kind', self::OUT)->map(fn($e) => $e->product_id . ':' . ($e->variant_id ?? 0))->all();
                $items   = $items->reject(fn($e) => in_array($e->product_id . ':' . ($e->variant_id ?? 0), $outKeys, true))->values();
                if (!$seller->stock_alerts_enabled) $items = collect();
            }
            if ($items->isEmpty()) continue;

            $payload = $this->describe($items);
            if (!$payload) continue;

            $notification = $kind === self::OUT
                ? new OutOfStockNotification($payload, $withEmail)
                : new LowStockNotification($payload, $withEmail);
            $seller->notify($notification);
        }
    }

    private function queue(User $seller, Product $product, ?ProductVariant $variant, string $kind, int $stock, int $threshold): bool
    {
        DB::table('stock_alert_events')->insert([
            'seller_id'  => $seller->id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'kind'       => $kind,
            'stock'      => $stock,
            'threshold'  => min(255, $threshold),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return true;
    }

    /**
     * One job per window: a job is already on its way when an earlier pending
     * row is younger than the window (an older one means its job was lost —
     * this new job picks it up too).
     */
    private function scheduleFlush(int $sellerId): void
    {
        $window = max(0, (int) config('stock.alert_group_window_minutes', 10));

        $pendingBefore = DB::table('stock_alert_events')
            ->where('seller_id', $sellerId)
            ->whereNull('notified_at')
            ->where('created_at', '>=', now()->subMinutes($window + 1))
            ->where('created_at', '<', now()->subSecond())
            ->exists();
        if ($pendingBefore) return;

        $job = FlushStockAlerts::dispatch($sellerId)->onQueue(config('seller_notifications.queue'));
        if ($window > 0) $job->delay(now()->addMinutes($window));
    }

    /** Items of a grouped notification, labelled in the product's original text. */
    private function describe($events): array
    {
        $products = Product::withTrashed()->whereIn('id', $events->pluck('product_id')->unique())->get()->keyBy('id');
        $variants = ProductVariant::with('attributeOptions')->whereIn('id', $events->pluck('variant_id')->filter()->unique())->get()->keyBy('id');

        $items = [];
        foreach ($events as $e) {
            $product = $products->get($e->product_id);
            if (!$product) continue;
            $variant = $e->variant_id ? $variants->get($e->variant_id) : null;
            $items[] = [
                'product_id'    => (int) $e->product_id,
                'variant_id'    => $e->variant_id ? (int) $e->variant_id : null,
                'name'          => $product->getRawOriginal('name') ?? $product->name,
                'variant_label' => $variant?->label ?: null,
                'stock'         => (int) $e->stock,
                'threshold'     => (int) $e->threshold,
            ];
        }
        return $items;
    }

    // ── Flags ────────────────────────────────────────────────────────────────

    /** Set the flag if empty. True only for the caller that set it. */
    private function claim(Product|ProductVariant $item, string $column): bool
    {
        return DB::table($item->getTable())->where('id', $item->id)->whereNull($column)->update([$column => now()]) === 1;
    }

    private function release(Product|ProductVariant $item, string $column): void
    {
        DB::table($item->getTable())->where('id', $item->id)->whereNotNull($column)->update([$column => null]);
    }
}
