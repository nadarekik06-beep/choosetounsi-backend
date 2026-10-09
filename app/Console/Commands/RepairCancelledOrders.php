<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair for orders cancelled before cancellation zeroed the money.
 *
 * The stored checkout amounts (orders.subtotal / shipping_fee / total_amount,
 * seller_orders financial snapshot) are history and are NOT changed: the
 * amount due is computed live (Order::moneySummary → 0 when cancelled).
 *
 * What it writes (only with --apply):
 *   seller_orders.payout_status pending → cancelled for cancelled sub-orders
 *   not in a settlement batch (they owe the seller nothing).
 *
 * What it only reports (manual review):
 *   cancelled sub-orders whose payout is ready / paid / batched,
 *   cancelled orders with live sub-orders, and the reverse.
 */
class RepairCancelledOrders extends Command
{
    protected $signature   = 'orders:repair-cancelled {--apply : Write the changes (default is a dry run)}';
    protected $description = 'Show / repair money state of cancelled orders (amount due 0, payouts cancelled)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLYING changes.' : 'DRY RUN — nothing is written. Re-run with --apply.');

        // ── 1. What each cancelled order shows, before → after ────────────────
        $orders = Order::with('sellerOrders')
            ->where(fn($q) => $q->where('status', 'cancelled')
                ->orWhereHas('sellerOrders', fn($s) => $s->where('status', 'cancelled')))
            ->orderBy('id')->get();

        $rows = $orders->map(function (Order $o) {
            $active   = $o->sellerOrders->where('status', '!=', 'cancelled');
            // The old summary: live items of active sub-orders + the full shipping fee
            $before   = $o->sellerOrders->isEmpty()
                ? (float) $o->total_amount
                : round($active->sum(fn($so) => (float) $so->subtotal - (float) $so->discount_amount) + (float) $o->shipping_fee, 3);
            $money    = $o->moneySummary();
            return [
                $o->id, $o->order_number, $o->status,
                $o->sellerOrders->pluck('status')->implode(',') ?: '—',
                number_format((float) $o->total_amount, 3), number_format($before, 3), number_format($money['total'], 3),
            ];
        });
        $this->line("\nOrders with cancelled parts — stored total is kept, displayed amount due changes:");
        $this->table(['id', 'order', 'status', 'sub-orders', 'stored total (kept)', 'shown before', 'due now'], $rows);

        // ── 2. Payouts of cancelled sub-orders ────────────────────────────────
        $payouts = DB::table('seller_orders as so')->join('orders as o', 'o.id', '=', 'so.order_id')
            ->where('so.status', 'cancelled')
            ->where('so.payout_status', '!=', 'cancelled')
            ->orderBy('so.id')
            ->get(['so.id', 'o.order_number', 'so.seller_id', 'so.payout_status', 'so.settlement_batch_id', 'so.seller_net_amount', 'so.commission_amount']);

        [$fixable, $review] = $payouts->partition(fn($so) => $so->payout_status === 'pending' && $so->settlement_batch_id === null);

        $this->line("\nCancelled sub-orders whose payout is still open → payout_status 'cancelled':");
        $this->table(['seller_order', 'order', 'seller', 'payout', 'frozen net (kept)', 'frozen commission (kept)'],
            $fixable->map(fn($so) => [$so->id, $so->order_number, $so->seller_id, $so->payout_status . ' → cancelled', $so->seller_net_amount, $so->commission_amount]));

        if ($review->isNotEmpty()) {
            $this->warn('Manual review (money already moved — not touched):');
            $this->table(['seller_order', 'order', 'payout', 'batch'],
                $review->map(fn($so) => [$so->id, $so->order_number, $so->payout_status, $so->settlement_batch_id ?? '—']));
        }

        // ── 3. Status mismatches (report only) ────────────────────────────────
        $mismatch = DB::table('orders as o')->join('seller_orders as so', 'so.order_id', '=', 'o.id')
            ->groupBy('o.id', 'o.order_number', 'o.status')
            ->havingRaw("(o.status = 'cancelled' AND SUM(so.status <> 'cancelled') > 0) OR (o.status <> 'cancelled' AND SUM(so.status <> 'cancelled') = 0)")
            ->get(['o.id', 'o.order_number', 'o.status', DB::raw("GROUP_CONCAT(so.status) as sub_statuses")]);
        if ($mismatch->isNotEmpty()) {
            $this->warn('Order / sub-order status mismatch (manual review, not touched):');
            $this->table(['id', 'order', 'status', 'sub-orders'], $mismatch->map(fn($r) => (array) $r));
        }

        if ($apply && $fixable->isNotEmpty()) {
            $n = DB::table('seller_orders')->whereIn('id', $fixable->pluck('id'))
                ->where('status', 'cancelled')->where('payout_status', 'pending')->whereNull('settlement_batch_id')
                ->update(['payout_status' => 'cancelled', 'updated_at' => now()]);
            $this->info("Updated {$n} seller order payout(s).");
        } else {
            $this->info("\n{$fixable->count()} payout(s) would change." . ($apply ? '' : ' Nothing written.'));
        }

        return self::SUCCESS;
    }
}
