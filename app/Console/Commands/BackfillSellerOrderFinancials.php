<?php
// app/Console/Commands/BackfillSellerOrderFinancials.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillSellerOrderFinancials extends Command
{
    protected $signature   = 'finance:backfill';
    protected $description = 'Backfill financial snapshots and sync delivery status on seller_orders';

    public function handle(): void
    {
        // ── STEP 1: Sync seller_order status from parent order ────────────────
        $this->info('Step 1: Syncing seller_order statuses from parent orders…');

        $synced = DB::table('seller_orders as so')
            ->join('orders as o', 'o.id', '=', 'so.order_id')
            ->where('o.status', 'delivered')
            ->where('so.status', '!=', 'delivered')
            ->select('so.id', 'o.payment_status')
            ->get();

        $this->info("Found {$synced->count()} seller_orders to sync.");

        foreach ($synced as $row) {
            DB::table('seller_orders')->where('id', $row->id)->update([
                'status'                 => 'delivered',
                'delivery_confirmed_at'  => now(),
                'updated_at'             => now(),
            ]);
        }

        $this->info('Status sync done.');

        // ── STEP 2: Auto-confirm money for COD/D17 orders that are paid ───────
        // If payment_status = 'paid' AND status = 'delivered', money is already in hand.
        $this->info('Step 2: Auto-confirming money for paid+delivered orders…');

        $readyToConfirm = DB::table('seller_orders as so')
            ->join('orders as o', 'o.id', '=', 'so.order_id')
            ->where('so.status', 'delivered')
            ->where('so.payout_status', 'pending')
            ->whereNotNull('so.delivery_confirmed_at')
            ->where('o.payment_status', 'paid')
            ->pluck('so.id');

        $this->info("Found {$readyToConfirm->count()} orders ready to confirm.");

        foreach ($readyToConfirm as $id) {
            DB::table('seller_orders')->where('id', $id)->update([
                'money_received_at' => now(),
                'payout_status'     => 'ready',
                'payment_status'    => 'paid',
                'updated_at'        => now(),
            ]);
        }

        $this->info('Money confirmation done.');

        // ── STEP 3: Backfill financial snapshots ──────────────────────────────
        $this->info('Step 3: Backfilling financial snapshots…');

        $service = app(\App\Services\FinancialSnapshotService::class);

        $ids = DB::table('seller_orders')
            ->where('commission_amount', 0)
            ->pluck('id');

        $this->info("Found {$ids->count()} seller_orders to freeze.");

        $bar = $this->output->createProgressBar($ids->count());
        $bar->start();

        foreach ($ids as $id) {
            $service->freeze($id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('All done.');
    }
}