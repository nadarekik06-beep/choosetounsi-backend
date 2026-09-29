<?php

namespace App\Console\Commands;

use App\Models\SellerApplication;
use App\Services\Ads\AdClock;
use App\Services\Ads\AdPricing;
use App\Services\Ads\AdWalletService;
use App\Services\PlanGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Gives every approved seller their plan tier's free ad credit for the month
 * (ads.monthly_credit: Green 0, Red 10, Black 40 by default). Last month's
 * unspent credit expires first; the new credit expires at the end of this
 * Africa/Tunis month. Safe to re-run: one grant per seller per month.
 *
 * Scheduled on the 1st at 00:10 Africa/Tunis.   php artisan ads:grant-monthly-credit
 */
class GrantMonthlyAdCredit extends Command
{
    protected $signature   = 'ads:grant-monthly-credit {--seller= : Only this seller id}';
    protected $description = 'Grant this month\'s free ad credit to sellers by plan tier';

    public function handle(AdWalletService $wallets, AdPricing $pricing, PlanGate $gate): int
    {
        $period  = AdClock::now()->format('Y-m');
        $expires = AdClock::endOfMonth();
        $granted = $total = 0;

        SellerApplication::where('status', 'approved')
            ->when($this->option('seller'), fn ($q, $id) => $q->where('user_id', $id))
            ->orderBy('id')
            ->each(function (SellerApplication $app) use ($wallets, $pricing, $gate, $period, $expires, &$granted, &$total) {
                try {
                    if ($gate->feature($app->user_id, 'sponsorships')) {
                        return;   // plan without sponsoring, or suspended
                    }
                    $amount = $pricing->monthlyCredit($gate->tierFor($app->user_id));
                    if ($amount <= 0) {
                        return;
                    }
                    if ($wallets->grantMonthlyCredit($app->user_id, $amount, $expires, $period)) {
                        $granted++;
                        $total += $amount;
                    }
                } catch (\Throwable $e) {
                    Log::error("[ads:grant-monthly-credit] seller #{$app->user_id}: " . $e->getMessage());
                }
            });

        $this->info("Granted {$period} credit to {$granted} seller(s), " . number_format($total, 3) . ' DT in total.');
        if ($granted > 0) {
            Log::info("[ads:grant-monthly-credit] {$period}: {$granted} sellers, {$total} DT");
        }
        return self::SUCCESS;
    }
}
