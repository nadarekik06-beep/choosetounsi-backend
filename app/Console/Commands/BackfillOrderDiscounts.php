<?php
// app/Console/Commands/BackfillOrderDiscounts.php

namespace App\Console\Commands;

use App\Services\CouponService;
use App\Services\FinancialSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings orders placed before the discount snapshot in line with the current
 * coupon rule:
 *
 *   order_items   → discount_amount (seller coupon split over eligible lines),
 *                   net_total, and commission re-applied on net_total with the
 *                   STORED rate (tier from the original unit price).
 *   seller_orders → coupon_type/value snapshot, commission/seller net re-aggregated,
 *                   delivery_fee booked on the order's first seller_order only.
 *   orders        → subtotal, discount_amount, coupon_codes, real shipping_fee.
 *
 * Idempotent: only order_items with net_total IS NULL are processed.
 * Seller orders already settled or refunded keep their frozen financials
 * (a payout already went out) and are listed for manual review.
 */
class BackfillOrderDiscounts extends Command
{
    protected $signature   = 'orders:backfill-discounts {--dry-run : Report what would change without writing}';
    protected $description = 'Backfill coupon discount snapshots and recalculate commission on post-discount prices';

    public function handle(CouponService $coupons, FinancialSnapshotService $snapshot): int
    {
        $dry = (bool) $this->option('dry-run');

        $sellerOrderIds = DB::table('order_items')
            ->whereNull('net_total')
            ->whereNotNull('seller_order_id')
            ->distinct()
            ->pluck('seller_order_id');

        $this->info("Seller orders to backfill: {$sellerOrderIds->count()}" . ($dry ? ' (dry run)' : ''));

        $recalculatedOrders = [];
        $skipped            = [];

        DB::beginTransaction();
        try {
            foreach ($sellerOrderIds as $soId) {
                $so    = DB::table('seller_orders')->where('id', $soId)->first();
                if (!$so) continue;
                $items = DB::table('order_items')->where('seller_order_id', $soId)->orderBy('id')->get();

                $discount = round((float) $so->discount_amount, 3);
                $coupon   = $so->coupon_id ? DB::table('coupons')->where('id', $so->coupon_id)->first() : null;

                // ── Allocate the seller discount over the coupon's eligible lines ──
                $shares = [];
                if ($discount > 0) {
                    $eligibleIds = $coupon
                        ? DB::table('coupon_products')->where('coupon_id', $coupon->id)->pluck('product_id')->all()
                        : [];
                    $eligible = $items->filter(fn($i) => (float) $i->total > 0 && in_array($i->product_id, $eligibleIds));
                    if ($eligible->isEmpty()) {
                        // Coupon deleted/edited since checkout — spread over all priced lines
                        $eligible = $items->filter(fn($i) => (float) $i->total > 0);
                    }
                    $shares = $coupons->allocateDiscount(
                        $eligible->mapWithKeys(fn($i) => [$i->id => (float) $i->total])->all(),
                        $discount
                    );
                }

                $frozen = $so->settlement_batch_id !== null || $so->payment_status === 'refunded';
                if ($frozen && $discount > 0) {
                    $skipped[] = $so->id;
                }

                $deltaCommission = 0.0;
                $deltaSeller     = 0.0;

                foreach ($items as $item) {
                    $share  = round($shares[$item->id] ?? 0.0, 3);
                    $net    = round((float) $item->total - $share, 3);
                    $update = ['discount_amount' => $share, 'net_total' => $net];

                    if ($share > 0 && !$frozen && (float) $item->commission_percentage > 0) {
                        $commission = round($net * ((float) $item->commission_percentage / 100), 3);
                        $seller     = round($net - $commission, 3);

                        $deltaCommission += $commission - (float) $item->commission_amount;
                        $deltaSeller     += $seller     - (float) $item->seller_amount;

                        $update['commission_amount'] = $commission;
                        $update['seller_amount']     = $seller;

                        $this->line(sprintf(
                            '  item #%d (SO #%d): fee %.3f → %.3f, seller %.3f → %.3f',
                            $item->id, $so->id, $item->commission_amount, $commission, $item->seller_amount, $seller
                        ));
                    }

                    if (!$dry) {
                        DB::table('order_items')->where('id', $item->id)->update($update);
                    }
                }

                $soUpdate = [
                    'coupon_type'  => $coupon?->discount_type,
                    'coupon_value' => $coupon?->discount_value,
                ];

                if ($discount > 0 && !$frozen) {
                    // Old rule stored seller_net = Σ seller_amount(pre-discount) − discount.
                    // New rule: seller_net = Σ seller_amount(post-discount). Undo the
                    // blanket deduction and apply the per-item deltas.
                    $soUpdate['commission_amount'] = round((float) $so->commission_amount + $deltaCommission, 3);
                    $soUpdate['seller_net_amount'] = round((float) $so->seller_net_amount + $discount + $deltaSeller, 3);
                    $recalculatedOrders[$so->order_id] = true;

                    $this->line(sprintf(
                        '  SO #%d: commission %.3f → %.3f, seller net %.3f → %.3f',
                        $so->id, $so->commission_amount, $soUpdate['commission_amount'],
                        $so->seller_net_amount, $soUpdate['seller_net_amount']
                    ));
                }

                if (!$dry) {
                    DB::table('seller_orders')->where('id', $so->id)->update($soUpdate);
                }
            }

            // ── Order-level snapshot + shipping ──────────────────────────────
            $orderIds = DB::table('orders')->whereNull('subtotal')->pluck('id');
            $shippingFixed = 0;

            foreach ($orderIds as $orderId) {
                $order = DB::table('orders')->where('id', $orderId)->first();
                $sos   = DB::table('seller_orders')->where('order_id', $orderId)->get();

                if ($sos->isEmpty()) {
                    // Pre-split legacy order: no line data to rebuild from
                    $update = [
                        'subtotal'        => max(0, round((float) $order->total_amount - (float) $order->shipping_fee, 3)),
                        'discount_amount' => 0,
                    ];
                } else {
                    $itemsTotal = round((float) DB::table('order_items')->where('order_id', $orderId)->sum('total'), 3);
                    $discount   = round((float) DB::table('order_items')->where('order_id', $orderId)->sum('discount_amount'), 3);
                    $codes      = $sos->pluck('coupon_code')->filter()->unique()->values()->all();

                    // Shipping the customer was actually charged at checkout
                    // (orders.shipping_fee was never written — it's the column default).
                    $activeSub  = $sos->where('status', '!=', 'cancelled')->sum(fn($s) => (float) $s->subtotal - (float) $s->discount_amount);
                    $charged    = round((float) $order->total_amount - $activeSub, 3);

                    $update = [
                        'subtotal'        => $itemsTotal,
                        'discount_amount' => $discount,
                        'coupon_codes'    => $codes ? json_encode($codes) : null,
                    ];
                    if ($charged >= 0 && abs($charged - (float) $order->shipping_fee) > 0.0005 && $charged <= 50) {
                        $update['shipping_fee'] = $charged;
                        $shippingFixed++;
                        $this->line("  order #{$orderId}: shipping_fee {$order->shipping_fee} → {$charged}");
                    }
                }

                if (!$dry) {
                    DB::table('orders')->where('id', $orderId)->update($update);
                }
            }

            // ── Shipping booked once per order, platform_profit re-derived ─────
            $profitFixed = 0;
            foreach (DB::table('seller_orders')->get() as $so) {
                $fee    = $dry ? (float) $so->delivery_fee : $snapshot->deliveryFeeFor($so->id);
                $profit = round((float) $so->commission_amount + $fee, 3);
                if (abs($fee - (float) $so->delivery_fee) > 0.0005 || abs($profit - (float) $so->platform_profit) > 0.0005) {
                    $profitFixed++;
                    if (!$dry) {
                        DB::table('seller_orders')->where('id', $so->id)->update([
                            'delivery_fee'    => $fee,
                            'platform_profit' => $profit,
                        ]);
                    }
                }
            }

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Backfill failed, nothing written: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Orders with commission recalculated (fee on post-discount price): ' . count($recalculatedOrders));
        if ($recalculatedOrders) {
            $numbers = DB::table('orders')->whereIn('id', array_keys($recalculatedOrders))->pluck('order_number')->join(', ');
            $this->line("  {$numbers}");
        }
        $this->info("Orders snapshotted: {$orderIds->count()} (shipping_fee corrected on {$shippingFixed})");
        $this->info("Seller orders with delivery_fee/platform_profit corrected: {$profitFixed}");
        if ($skipped) {
            $this->warn('Settled/refunded seller orders left unchanged (review manually): #' . implode(', #', $skipped));
        }

        return self::SUCCESS;
    }
}
