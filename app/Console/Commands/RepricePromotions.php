<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Promotion;
use App\Services\PriceHistory;
use App\Services\PromotionService;
use App\Support\Millimes;
use App\Support\PricingCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Promotions used to be priced from the lowest price of the last 30 days, so a
 * product whose price went up (100 → 150 DT) was sold at 20 % off 100 = 80 DT
 * instead of 120 DT. Promotions are now priced from the CURRENT price at read
 * time; the promotions table stores no price, so nothing there is stale.
 *
 * What can still hold an old promo price:
 *   - carts.promo_price_snapshot: the price shown when the row was added (never
 *     charged, only used for the "promotion ended" notice);
 *   - the 60-120 s display caches (deals, promo flyers, home feed).
 *
 * Dry run (default): lists every live or upcoming promotion's product / variant
 * whose price changes with the new rule (old 30-day price → new current price)
 * and the cart rows that would be refreshed.
 * --apply: refreshes those cart snapshots and the display caches.
 *
 * Never touches orders: past orders keep the prices they were placed at.
 */
class RepricePromotions extends Command
{
    protected $signature   = 'promotions:reprice {--apply : Refresh cart snapshots and price caches (default: dry run)}';
    protected $description = 'Report promotions whose price changes now that they use the current price, and refresh stale cart snapshots';

    public function handle(PromotionService $promos): int
    {
        $apply = (bool) $this->option('apply');
        PriceHistory::flush();

        $promotions = Promotion::query()
            ->whereIn('status', Promotion::PRICING_STATUSES)
            ->where('ends_at', '>', now())
            ->with(['products' => fn ($q) => $q->with(['variants' => fn ($v) => $v->where('is_active', true)])])
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($promotions as $promo) {
            foreach ($promo->products as $product) {
                $lines = [[null, (float) $product->price]];
                foreach ($product->variants as $variant) {
                    if ($variant->price_override !== null) {
                        $lines[] = [$variant, (float) $variant->price_override];
                    }
                }
                foreach ($lines as [$variant, $current]) {
                    $old = PriceHistory::lowest($product->id, $variant?->id, $current);
                    $oldFinalM = PromotionService::discountedMillimes(Millimes::of($old), $promo);
                    $newFinalM = PromotionService::discountedMillimes(Millimes::of($current), $promo);
                    if ($oldFinalM === $newFinalM) continue;
                    $rows[] = [
                        $promo->id,
                        mb_strimwidth($promo->name, 0, 28, '…'),
                        $product->id . ($variant ? " / v{$variant->id}" : ''),
                        mb_strimwidth($product->name . ($variant ? " — {$variant->label}" : ''), 0, 36, '…'),
                        number_format($current, 3, '.', ''),
                        number_format($old, 3, '.', ''),
                        $oldFinalM > 0 ? number_format(Millimes::toFloat($oldFinalM), 3, '.', '') : 'ignored',
                        $newFinalM > 0 ? number_format(Millimes::toFloat($newFinalM), 3, '.', '') : 'ignored',
                    ];
                }
            }
        }

        $this->info($promotions->count() . ' live or upcoming promotion(s) checked.');
        if ($rows) {
            $this->table(['Promo', 'Name', 'Product', 'Item', 'Current', 'Old base', 'Old final', 'New final'], $rows);
        } else {
            $this->line('No promotion price changes: every item already had its current price as the lowest 30-day price.');
        }

        // Cart rows whose shown promo price is no longer what the live price is
        $stale = [];
        $carts = DB::table('carts')
            ->whereNull('pack_id')
            ->whereNotNull('promotion_id_snapshot')
            ->whereNotNull('promo_price_snapshot')
            ->orderBy('id')
            ->get(['id', 'product_id', 'variant_id', 'promo_price_snapshot']);
        $products = Product::with('variants')->whereIn('id', $carts->pluck('product_id')->unique())->get()->keyBy('id');

        foreach ($carts as $row) {
            $product = $products->get($row->product_id);
            if (!$product) continue;
            $variant = $row->variant_id ? $product->variants->firstWhere('id', $row->variant_id) : null;
            $pricing = $promos->priceLine($product, $variant);
            if (!$pricing['promotion']) continue;   // ended: the "promotion ended" notice needs the old figure
            if (Millimes::of($pricing['final_price']) !== Millimes::of($row->promo_price_snapshot)) {
                $stale[] = ['id' => $row->id, 'promotion_id' => $pricing['promotion']['id'], 'price' => $pricing['final_price'],
                            'old' => (float) $row->promo_price_snapshot];
            }
        }
        $this->info(count($stale) . ' cart row(s) with an outdated promo price snapshot.');

        if (!$apply) {
            $this->warn('Dry run: nothing written. Run with --apply to refresh cart snapshots and price caches.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($stale) {
            foreach ($stale as $s) {
                DB::table('carts')->where('id', $s['id'])->update([
                    'promotion_id_snapshot' => $s['promotion_id'],
                    'promo_price_snapshot'  => $s['price'],
                    'updated_at'            => now(),
                ]);
            }
        });
        PricingCache::bump();

        $this->info('Applied: ' . count($stale) . ' cart row(s) refreshed, price caches rebuilt. Orders untouched.');
        return self::SUCCESS;
    }
}
