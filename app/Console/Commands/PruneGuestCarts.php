<?php

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Command;

/** Deletes guest cart lines (no account) untouched for guest_checkout.cart_ttl_days. */
class PruneGuestCarts extends Command
{
    protected $signature = 'carts:prune-guests';

    protected $description = 'Delete abandoned guest cart lines';

    public function handle(): int
    {
        $days    = max(1, (int) config('guest_checkout.cart_ttl_days', 30));
        $deleted = Cart::whereNull('user_id')
            ->whereNotNull('guest_token')
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} guest cart line(s) older than {$days} days.");
        return self::SUCCESS;
    }
}
