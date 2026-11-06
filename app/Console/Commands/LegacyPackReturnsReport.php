<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY report (AUDIT_DISCOUNTS C-1). Before the fix, a pack was stored as
 * N order lines with ALL the money on the first one; a partial return then
 * refunded either the whole pack or nothing. This lists every legacy pack
 * (pack_id null, "(Bundle: …)" lines) that had a return touching only part of
 * it, with what was refunded vs a pro-rata estimate. Nothing is modified.
 *
 *   php artisan discounts:legacy-pack-returns
 *   php artisan discounts:legacy-pack-returns --csv=storage/app/legacy-pack-returns.csv
 */
class LegacyPackReturnsReport extends Command
{
    protected $signature   = 'discounts:legacy-pack-returns {--csv= : also write the rows to this CSV file}';
    protected $description = 'Read-only: legacy pack orders that had a partial return (refund may be wrong)';

    public function handle(): int
    {
        $lines = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->whereNull('oi.pack_id')
            ->where('oi.product_name', 'like', '% (Bundle: %)')
            ->orderBy('oi.order_id')->orderBy('oi.id')
            ->get([
                'oi.id', 'oi.order_id', 'o.order_number', 'oi.seller_order_id', 'oi.product_name', 'oi.quantity',
                'oi.returned_quantity', 'oi.returned_amount', 'oi.total', 'p.price as product_price',
            ]);

        // Units in returns still in progress (not refunded yet)
        $pending = DB::table('complaint_items as ci')
            ->join('complaints as c', 'c.id', '=', 'ci.complaint_id')
            ->whereNull('c.finance_applied_at')
            ->whereNotIn('c.status', ['cancelled', 'rejected'])
            ->whereIn('ci.order_item_id', $lines->pluck('id'))
            ->groupBy('ci.order_item_id')
            ->selectRaw('ci.order_item_id, SUM(ci.quantity) as qty')
            ->pluck('qty', 'order_item_id');

        $rows = [];
        $groups = $lines->groupBy(function ($l) {
            preg_match('/ \(Bundle: (.+)\)$/u', (string) $l->product_name, $m);
            return $l->order_id . '|' . ($l->seller_order_id ?? 0) . '|' . ($m[1] ?? '?');
        });

        foreach ($groups as $key => $group) {
            $ordered  = $group->sum(fn ($l) => (int) $l->quantity + (int) $l->returned_quantity);
            $returned = $group->sum(fn ($l) => (int) $l->returned_quantity + (int) ($pending[$l->id] ?? 0));
            $partial  = $returned > 0 && ($returned < $ordered
                || $group->contains(fn ($l) => (int) $l->returned_quantity + (int) ($pending[$l->id] ?? 0) < (int) $l->quantity + (int) $l->returned_quantity));
            if (!$partial) {
                continue;
            }

            // Pro-rata estimate: pack money (Σ totals + refunds) split by product price × units
            $packMoney = $group->sum(fn ($l) => (float) $l->total + (float) $l->returned_amount);
            $weight    = $group->sum(fn ($l) => (float) $l->product_price * ((int) $l->quantity + (int) $l->returned_quantity));
            $fair      = $weight > 0 ? $group->sum(fn ($l) => $packMoney * (float) $l->product_price * (int) $l->returned_quantity / $weight) : 0;
            $refunded  = $group->sum(fn ($l) => (float) $l->returned_amount);
            [, , $pack] = explode('|', $key, 3);

            $rows[] = [
                'order_id'        => $group->first()->order_id,
                'order_number'    => $group->first()->order_number,
                'seller_order_id' => $group->first()->seller_order_id,
                'pack'            => $pack,
                'units_returned'  => "{$returned}/{$ordered}",
                'in_progress'     => $group->contains(fn ($l) => ($pending[$l->id] ?? 0) > 0) ? 'yes' : 'no',
                'refunded'        => number_format($refunded, 3, '.', ''),
                'pro_rata'        => number_format($fair, 3, '.', ''),
                'difference'      => number_format($refunded - $fair, 3, '.', ''),
            ];
        }

        if (!$rows) {
            $this->info('No legacy pack order with a partial return.');
            return self::SUCCESS;
        }

        $this->table(array_keys($rows[0]), $rows);
        $this->warn(count($rows) . ' legacy pack order(s) had a partial return. Nothing was changed; review them by hand.');

        if ($path = $this->option('csv')) {
            $fh = fopen(base_path($path), 'w');
            fputcsv($fh, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($fh, $row);
            }
            fclose($fh);
            $this->info("Written to {$path}");
        }
        return self::SUCCESS;
    }
}
