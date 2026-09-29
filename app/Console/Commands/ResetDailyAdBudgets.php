<?php

namespace App\Console\Commands;

use App\Models\Sponsorship;
use App\Services\Ads\AdClock;
use App\Services\Ads\AdServer;
use App\Services\Ads\AdWalletService;
use App\Services\Ads\SponsorshipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * New Africa/Tunis day: today's spend starts from zero and campaigns that hit
 * yesterday's budget cap resume. One that still can't run switches to the pause
 * that explains why (wallet empty / out of stock), so the seller is told.
 *
 * Scheduled daily at 00:05 Africa/Tunis.   php artisan ads:reset-daily
 */
class ResetDailyAdBudgets extends Command
{
    protected $signature   = 'ads:reset-daily';
    protected $description = 'Reset daily ad spend and resume campaigns paused by the daily budget cap';

    public function handle(SponsorshipService $campaigns, AdWalletService $wallets): int
    {
        $today = AdClock::today();
        Sponsorship::where('spent_today', '>', 0)
            ->where(fn ($q) => $q->whereNull('spent_today_date')->orWhere('spent_today_date', '<', $today))
            ->update(['spent_today' => 0, 'spent_today_date' => $today]);

        $resumed = $blocked = 0;
        Sponsorship::where('status', Sponsorship::STATUS_PAUSED)
            ->where('paused_reason', Sponsorship::PAUSE_BUDGET_TODAY)
            ->orderBy('id')
            ->each(function (Sponsorship $c) use ($campaigns, $wallets, &$resumed, &$blocked) {
                try {
                    if ($campaigns->resumeAutomatically($c)) {
                        $resumed++;
                        return;
                    }
                    $c = $c->fresh();
                    if ($c->status === Sponsorship::STATUS_PAUSED && $c->paused_reason === Sponsorship::PAUSE_BUDGET_TODAY) {
                        $reason = $wallets->available($c->seller_id) <= 0
                            ? Sponsorship::PAUSE_WALLET_EMPTY : Sponsorship::PAUSE_OUT_OF_STOCK;
                        $campaigns->repause($c, $reason);
                        $blocked++;
                    }
                } catch (\Throwable $e) {
                    Log::error("[ads:reset-daily] campaign #{$c->id}: " . $e->getMessage());
                }
            });

        AdServer::flushEligible();
        $this->info("Resumed {$resumed} campaign(s); {$blocked} still blocked.");
        return self::SUCCESS;
    }
}
