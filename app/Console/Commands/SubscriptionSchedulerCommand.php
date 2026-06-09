<?php
// app/Console/Commands/SubscriptionSchedulerCommand.php
//
// Artisan command: php artisan subscriptions:process
//
// Run daily via Kernel.php (see KERNEL_PATCH below).
// Handles:
//   1. Billing cycles that have expired → apply pending downgrade OR start grace
//   2. Grace periods that have expired  → revert to free plan
//   3. Sends reminder emails 3 days and 1 day before billing_cycle_end

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use App\Models\SellerSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class SubscriptionSchedulerCommand extends Command
{
    protected $signature   = 'subscriptions:process';
    protected $description = 'Process subscription lifecycle: expired cycles, grace periods, renewal reminders';

    public function __construct(private SubscriptionService $subscriptionService) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('[SubscriptionScheduler] Starting...');

        // ── 1. Send renewal reminders (3 days and 1 day before cycle end) ─────
        $reminders = $this->sendRenewalReminders();
        $this->line("  Renewal reminders sent: {$reminders}");

        // ── 2. Process expired billing cycles ─────────────────────────────────
        $expired = $this->subscriptionService->processExpiredCycles();
        $this->line("  Expired billing cycles processed: {$expired}");

        // ── 3. Process expired grace periods ──────────────────────────────────
        $graceExpired = $this->subscriptionService->processExpiredGrace();
        $this->line("  Expired grace periods processed: {$graceExpired}");

        $this->info('[SubscriptionScheduler] Done.');
        Log::info("[SubscriptionScheduler] reminders={$reminders} expired={$expired} graceExpired={$graceExpired}");

        return 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RENEWAL REMINDERS
    // Sends database notifications 3 days and 1 day before billing_cycle_end.
    // Uses the existing Laravel notification system already in the project.
    // ─────────────────────────────────────────────────────────────────────────

    private function sendRenewalReminders(): int
    {
        $sent = 0;

        $reminderDays = [3, 1];

        foreach ($reminderDays as $days) {
            $targetDate = Carbon::today()->addDays($days)->toDateString();

            SellerSubscription::where('status', 'active')
                ->where('billing_cycle_end', $targetDate)
                ->whereIn('current_plan', ['red', 'black'])
                ->with('user')
                ->get()
                ->each(function (SellerSubscription $sub) use ($days, &$sent) {
                    try {
                        if (! $sub->user) return;

                        // Check if we already sent this reminder today (dedup)
                        $alreadySent = \DB::table('notifications')
                            ->where('notifiable_id', $sub->user_id)
                            ->where('notifiable_type', 'App\\Models\\User')
                            ->whereDate('created_at', today())
                            ->whereRaw("JSON_EXTRACT(data, '$.source') = 'subscription_renewal_reminder'")
                            ->whereRaw("JSON_EXTRACT(data, '$.days_remaining') = {$days}")
                            ->exists();

                        if ($alreadySent) return;

                        $planLabel = $sub->current_plan === 'red' ? 'Red Pepper' : 'Black Pepper';
                        $price     = $sub->current_plan === 'red' ? 49 : 129;

                        $sub->user->notify(new \App\Notifications\BlackSmartNotification([
                            'source'        => 'subscription_renewal_reminder',
                            'days_remaining'=> $days,
                            'notify_type'   => 'renewal_reminder',
                            'title'         => $days === 1
                                ? "Your {$planLabel} plan renews tomorrow"
                                : "Your {$planLabel} plan renews in {$days} days",
                            'body'          => "Your subscription ({$price} DT/month) will auto-renew on {$sub->billing_cycle_end->format('d M Y')}.",
                            'icon'          => 'credit-card',
                            'action'        => 'manage_plan',
                            'link'          => '/seller/subscription',
                        ]));

                        $sent++;
                    } catch (\Throwable $e) {
                        \Log::warning("[SubscriptionScheduler] Reminder failed for sub #{$sub->id}: " . $e->getMessage());
                    }
                });
        }

        return $sent;
    }
}

/*
|─────────────────────────────────────────────────────────────────────────────
| KERNEL_PATCH — add this to app/Console/Kernel.php
|─────────────────────────────────────────────────────────────────────────────
|
| In the $commands array, add:
|   \App\Console\Commands\SubscriptionSchedulerCommand::class,
|
| In the schedule() method, add:
|   $schedule->command('subscriptions:process')
|       ->dailyAt('02:00')          // run at 2am — low traffic time
|       ->withoutOverlapping()
|       ->runInBackground()
|       ->onSuccess(function () {
|           \Illuminate\Support\Facades\Log::info('[Kernel] subscriptions:process OK');
|       })
|       ->onFailure(function () {
|           \Illuminate\Support\Facades\Log::error('[Kernel] subscriptions:process FAILED');
|       });
|
| Test manually:
|   php artisan subscriptions:process
|
| Check schedule:
|   php artisan schedule:list
|─────────────────────────────────────────────────────────────────────────────
*/