<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\Sellers\FreeDeliveryEverywhereNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tells the sellers whose products offered free delivery (cleared by migration
 * 2026_11_11_000002, ids kept in legacy_free_delivery_products) that delivery
 * is now free everywhere and included in the displayed price.
 *
 *   php artisan pricing:notify-free-delivery-sellers            dry run: who would be told
 *   php artisan pricing:notify-free-delivery-sellers --send     bell + e-mail, once per seller
 *
 * Idempotent: a seller already told (seller_notified_at) is skipped. Run it
 * once after the deploy; nothing is sent by the migration itself.
 */
class NotifyFreeDeliverySellers extends Command
{
    protected $signature = 'pricing:notify-free-delivery-sellers {--send : really send (default: dry run)}';

    protected $description = 'Notify sellers whose products offered free delivery that delivery is now included in the displayed price';

    public function handle(): int
    {
        $rows = DB::table('legacy_free_delivery_products')
            ->whereNotNull('seller_id')
            ->whereNull('seller_notified_at')
            ->groupBy('seller_id')
            ->selectRaw('seller_id, COUNT(*) as products')
            ->pluck('products', 'seller_id');

        if ($rows->isEmpty()) {
            $this->info('No seller left to notify.');
            return self::SUCCESS;
        }

        $sent = 0;
        foreach (User::whereIn('id', $rows->keys())->get() as $seller) {
            $count = (int) $rows[$seller->id];
            $this->line(sprintf('%s  #%d %s (%d product%s)', $this->option('send') ? 'notify' : 'would notify',
                $seller->id, $seller->name, $count, $count > 1 ? 's' : ''));
            if (!$this->option('send')) {
                continue;
            }
            $seller->notify((new FreeDeliveryEverywhereNotification($count))->locale($seller->preferredLocale()));
            DB::table('legacy_free_delivery_products')->where('seller_id', $seller->id)->update(['seller_notified_at' => now()]);
            $sent++;
        }

        $this->info($this->option('send') ? "{$sent} seller(s) notified." : 'Dry run: nothing sent. Add --send to notify.');
        return self::SUCCESS;
    }
}
