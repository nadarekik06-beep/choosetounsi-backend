<?php

namespace App\Console\Commands;

use App\Services\Sellers\SuspendedSellerProducts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time catch-up for sellers suspended before SellerSuspensionObserver existed:
 * hides the live products of every seller with users.is_active = 0
 * (is_active = false, hidden_reason = 'seller_suspended'). Nothing is deleted;
 * reactivating the seller in the admin panel restores them.
 *
 *   php artisan sellers:hide-suspended-products            # dry run, lists what would change
 *   php artisan sellers:hide-suspended-products --apply
 */
class HideSuspendedSellerProducts extends Command
{
    protected $signature = 'sellers:hide-suspended-products {--apply : Write the changes (default: dry run)}';

    protected $description = 'Hide the storefront products of sellers that are already suspended';

    public function handle(SuspendedSellerProducts $service): int
    {
        $sellers = DB::table('users')->where('role', 'seller')->where('is_active', false)
            ->orderBy('id')->get(['id', 'name', 'email']);

        $rows = [];
        foreach ($sellers as $seller) {
            $live = $service->visible((int) $seller->id)->count();
            if ($live === 0) continue;
            $rows[] = [$seller->id, $seller->name, $live];
        }

        if (!$rows) {
            $this->info('No suspended seller has live products. Nothing to do.');
            return self::SUCCESS;
        }

        $this->table(['Seller id', 'Name', 'Live products'], $rows);

        if (!$this->option('apply')) {
            $this->warn('Dry run: nothing changed. Re-run with --apply to hide these products.');
            return self::SUCCESS;
        }

        $total = 0;
        foreach ($rows as [$id]) $total += $service->hide((int) $id);
        $this->info("Hidden {$total} products.");

        return self::SUCCESS;
    }
}
