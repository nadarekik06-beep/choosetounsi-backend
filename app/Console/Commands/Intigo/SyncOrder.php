<?php

namespace App\Console\Commands\Intigo;

use App\Models\Order;
use App\Models\SellerOrder;
use App\Services\Intigo\IntigoClient;
use App\Services\Intigo\IntigoStatusSync;
use Illuminate\Console\Command;

/**
 * Reads an order's Intigo parcels (GET /parcels/{nid}, read-only) and applies
 * their status like a webhook would. Never creates, changes or cancels a parcel.
 */
class SyncOrder extends Command
{
    protected $signature = 'intigo:sync-order {order_number : e.g. ORD-VLIQ7ART}';

    protected $description = 'Read-only: fetch the Intigo status of an order\'s parcels and apply it (same logic as the webhook)';

    public function handle(IntigoClient $client, IntigoStatusSync $sync): int
    {
        $order = Order::where('order_number', strtoupper(trim($this->argument('order_number'))))->first();
        if (!$order) {
            $this->error('Order not found.');
            return self::FAILURE;
        }
        $parcels = SellerOrder::where('order_id', $order->id)->whereNotNull('intigo_nid')->orderBy('id')->get();
        if ($parcels->isEmpty()) {
            $this->warn('No parcel of this order was sent to Intigo.');
            return self::SUCCESS;
        }
        $failed = false;
        foreach ($parcels as $so) {
            try {
                $r = $sync->syncParcel($so, $client);
                $this->line("seller_order {$so->id} / {$so->intigo_nid}: Intigo {$r['code']} {$r['label']} → {$r['result']} (now: {$so->fresh()->status})");
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("seller_order {$so->id} / {$so->intigo_nid}: " . $e->getMessage());
            }
        }
        $this->line("Order {$order->order_number}: " . $order->fresh()->status);
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
