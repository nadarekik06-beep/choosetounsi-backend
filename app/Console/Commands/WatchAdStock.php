<?php

namespace App\Console\Commands;

use App\Models\Sponsorship;
use App\Services\Ads\ReadinessService;
use App\Services\Ads\SponsorshipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Campaigns follow their product: out of stock or unlisted → paused (seller told);
 * sellable again (or wallet topped up) → resumed.
 *
 * Scheduled every 15 minutes.   php artisan ads:stock-watch
 */
class WatchAdStock extends Command
{
    protected $signature   = 'ads:stock-watch';
    protected $description = 'Pause campaigns whose product became unavailable and resume those that can run again';

    public function handle(SponsorshipService $campaigns, ReadinessService $readiness): int
    {
        $paused = $resumed = 0;

        Sponsorship::with('product')->where('status', Sponsorship::STATUS_ACTIVE)->orderBy('id')
            ->each(function (Sponsorship $c) use ($campaigns, $readiness, &$paused) {
                $p = $c->product;
                $reason = !$p || $p->trashed() || !$p->is_approved || !$p->is_active
                    ? Sponsorship::PAUSE_PRODUCT_INACTIVE
                    : ($readiness->totalStock($p) <= 0 ? Sponsorship::PAUSE_OUT_OF_STOCK : null);
                if ($reason && $this->attempt(fn () => $campaigns->pause($c, $reason))) {
                    $paused++;
                }
            });

        Sponsorship::where('status', Sponsorship::STATUS_PAUSED)
            ->whereIn('paused_reason', [Sponsorship::PAUSE_OUT_OF_STOCK, Sponsorship::PAUSE_PRODUCT_INACTIVE, Sponsorship::PAUSE_WALLET_EMPTY])
            ->orderBy('id')
            ->each(function (Sponsorship $c) use ($campaigns, &$resumed) {
                if ($this->attempt(fn () => $campaigns->resumeAutomatically($c))) {
                    $resumed++;
                }
            });

        $this->info("Paused {$paused}, resumed {$resumed} campaign(s).");
        return self::SUCCESS;
    }

    private function attempt(callable $fn): bool
    {
        try {
            return (bool) $fn();
        } catch (\Throwable $e) {
            Log::warning('[ads:stock-watch] ' . $e->getMessage());
            return false;
        }
    }
}
