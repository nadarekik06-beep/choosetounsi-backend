<?php

namespace App\Services\Orders;

use App\Exceptions\InsufficientStock;
use App\Services\StockAlertService;
use Illuminate\Support\Facades\DB;

/**
 * The single place order lines move stock.
 *
 *   checkout            reserve()   units leave the shelf while the order is pending,
 *                                   so two buyers can't both get the last one
 *   cancelled           release()   the sub-order's lines go back, once
 *   item returned       release()   (refund pickup) the returned lines go back, once
 *   cancelled → reopen  reclaim()   lines released by the cancel are reserved again
 *
 * Every line holds its stock on its own variant when it has one, otherwise on
 * the product. order_items.stock_restored_at is what makes release() idempotent:
 * a line is only ever given back while that column is empty, and the line row
 * is locked while it is checked and stamped.
 */
class OrderStock
{
    public const CANCELLED = 'cancelled';
    public const RETURNED  = 'returned';
    /** Refused at the door, given back once the parcel is returned to the seller. */
    public const REFUSED   = 'refused';

    /**
     * Take $qty units for one line. Conditional decrement under a row lock: the
     * stock can never go negative even when two checkouts race for the last unit.
     */
    public function reserve(?int $variantId, int $productId, int $qty, string $label): void
    {
        [$table, $id] = $variantId ? ['product_variants', $variantId] : ['products', $productId];

        $available = (int) DB::table($table)->where('id', $id)->lockForUpdate()->value('stock');
        if ($available < $qty) {
            throw new InsufficientStock($label, max(0, $available));
        }
        DB::table($table)->where('id', $id)->decrement('stock', $qty);
    }

    /** Cancelled sub-orders: every line not given back yet goes back to stock. Returns units restored. */
    public function releaseForSellerOrders(array $sellerOrderIds, string $reason = self::CANCELLED): int
    {
        return $sellerOrderIds
            ? $this->release(fn ($q) => $q->whereIn('seller_order_id', $sellerOrderIds), $reason)
            : 0;
    }

    /** Lines collected back from the customer (refund pickup). */
    public function releaseForOrderItems(array $orderItemIds, string $reason = self::RETURNED): int
    {
        return $orderItemIds
            ? $this->release(fn ($q) => $q->whereIn('id', $orderItemIds), $reason)
            : 0;
    }

    /**
     * A cancelled sub-order put back in play (admin/seller changed the status
     * again): the lines its cancel gave back are reserved again. Lines given
     * back because the customer returned them stay on the shelf.
     *
     * @throws InsufficientStock when the units were sold to someone else meanwhile
     */
    public function reclaimForSellerOrders(array $sellerOrderIds): int
    {
        if (!$sellerOrderIds) {
            return 0;
        }

        [$units, $lines] = DB::transaction(function () use ($sellerOrderIds) {
            $lines = DB::table('order_items')
                ->whereIn('seller_order_id', $sellerOrderIds)
                ->where('stock_restored_reason', self::CANCELLED)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'product_id', 'variant_id', 'quantity', 'product_name', 'variant_label']);

            $units = 0;
            foreach ($lines as $line) {
                $label = $line->product_name . ($line->variant_label ? " ({$line->variant_label})" : '');
                $this->reserve($line->variant_id, (int) $line->product_id, (int) $line->quantity, $label);
                $units += (int) $line->quantity;
            }

            if ($lines->isNotEmpty()) {
                DB::table('order_items')->whereIn('id', $lines->pluck('id'))
                    ->update(['stock_restored_at' => null, 'stock_restored_reason' => null]);
            }
            return [$units, $lines];
        });

        $this->afterStockMoved($lines);
        return $units;
    }

    private function release(\Closure $scope, string $reason): int
    {
        [$units, $lines] = DB::transaction(function () use ($scope, $reason) {
            $lines = $scope(DB::table('order_items'))
                ->whereNull('stock_restored_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'product_id', 'variant_id', 'quantity']);

            $units = 0;
            foreach ($lines as $line) {
                $qty = (int) $line->quantity;
                if ($qty <= 0) {
                    continue;
                }
                $line->variant_id
                    ? DB::table('product_variants')->where('id', $line->variant_id)->increment('stock', $qty)
                    : DB::table('products')->where('id', $line->product_id)->increment('stock', $qty);
                $units += $qty;
            }

            if ($lines->isNotEmpty()) {
                DB::table('order_items')->whereIn('id', $lines->pluck('id'))
                    ->update(['stock_restored_at' => now(), 'stock_restored_reason' => $reason]);
            }
            return [$units, $lines];
        });

        $this->afterStockMoved($lines);
        return $units;
    }

    /**
     * After the commit (never under the row locks): product totals of variant
     * products and stock-alert flags follow the new stock (back above the
     * threshold → alerts re-armed). Checkout does the same for its own lines.
     */
    private function afterStockMoved($lines): void
    {
        $alerts = app(StockAlertService::class);
        foreach (collect($lines)->unique(fn($l) => $l->product_id . ':' . ($l->variant_id ?? 0)) as $line) {
            $alerts->restocked((int) $line->product_id, $line->variant_id ? (int) $line->variant_id : null);
        }
    }
}
