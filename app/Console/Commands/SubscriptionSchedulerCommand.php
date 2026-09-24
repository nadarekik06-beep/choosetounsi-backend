<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily subscription lifecycle (scheduled in App\Console\Kernel at 02:00).
 *
 *   1. Expiry reminders 7 days and 1 day before a paid period / trial ends
 *   2. Trials that ended            → default plan
 *   3. Paid periods that ended      → scheduled downgrade / cancellation applied,
 *                                     otherwise a GRACE_PERIOD_DAYS grace period
 *   4. Grace periods that ran out   → default plan
 *   5. Expired commission overrides → cleared
 *
 * Moving to a plan with a lower product limit hides the oldest extra products
 * (hidden_reason = over_plan_limit). Nothing is ever deleted; upgrading restores them.
 *
 * Run once manually:  php artisan subscriptions:process
 */
class SubscriptionSchedulerCommand extends Command
{
    protected $signature   = 'subscriptions:process';
    protected $description = 'Process subscription lifecycle: reminders, trials, expired cycles, grace periods, commission overrides';

    public function __construct(private SubscriptionService $subscriptionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('[SubscriptionScheduler] Starting...');

        $results = [
            'reminders'         => $this->subscriptionService->sendExpiryReminders([7, 1]),
            'trials_ended'      => $this->subscriptionService->processExpiredTrials(),
            'cycles_ended'      => $this->subscriptionService->processExpiredCycles(),
            'grace_ended'       => $this->subscriptionService->processExpiredGrace(),
            'overrides_expired' => $this->subscriptionService->processExpiredOverrides(),
        ];

        foreach ($results as $label => $n) {
            $this->line(sprintf('  %-18s %d', $label . ':', $n));
        }

        $this->info('[SubscriptionScheduler] Done.');
        Log::info('[SubscriptionScheduler] ' . http_build_query($results, '', ' '));

        return 0;
    }
}
