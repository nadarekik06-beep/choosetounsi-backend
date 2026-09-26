<?php
// app/Services/SubscriptionService.php

namespace App\Services;

use App\Models\SellerApplication;
use App\Models\SellerSubscription;
use App\Models\SubscriptionAuditLog;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanChange;
use App\Models\User;
use App\Notifications\SubscriptionUpdatedNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SubscriptionService
 *
 * Single source of truth for ALL subscription lifecycle logic on ChooseTounsi.
 *
 * Seller-facing:   upgrade, scheduleDowngrade, cancelPendingDowngrade
 * Admin-facing:    assignPlan (adminForce), changeEndDate, grantFreeDays, startTrial,
 *                  suspend, reactivate, cancel, setCommissionOverride, removeCommissionOverride
 * Scheduler:       sendExpiryReminders, processExpiredTrials, processExpiredCycles,
 *                  processExpiredGrace, processExpiredOverrides
 *
 * INVARIANTS
 *   - every plan change writes BOTH seller_applications.plan AND
 *     seller_subscriptions.current_plan (middleware and checkout read both);
 *   - every state change writes a subscription_audit_logs row;
 *   - every admin / lifecycle change notifies the seller.
 */
class SubscriptionService
{
    public function __construct(
        private PlanDowngradeService $downgradeService
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET OR CREATE SUBSCRIPTION ROW (idempotent)
    // ─────────────────────────────────────────────────────────────────────────

    public function getOrCreateSubscription(SellerApplication $app): SellerSubscription
    {
        $sub = SellerSubscription::where('seller_application_id', $app->id)->first();
        if ($sub) return $sub;

        $plan = SubscriptionPlan::forSlug($app->plan);
        $paid = !$plan->isFree();

        return SellerSubscription::create([
            'seller_application_id' => $app->id,
            'user_id'               => $app->user_id,
            'current_plan'          => $plan->slug,
            'pending_plan'          => null,
            'status'                => 'active',
            'billing_period'        => 'monthly',
            'billing_cycle_start'   => $paid ? today() : null,
            'billing_cycle_end'     => $paid ? today()->addDays(30) : null,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // SELLER ACTIONS
    // ═════════════════════════════════════════════════════════════════════════

    /** Seller paid for a higher plan — immediate effect. */
    public function upgrade(
        SellerApplication $app,
        string $targetPlan,
        SubscriptionPayment $payment,
        ?User $actor = null,
        string $billingPeriod = 'monthly'
    ): SellerSubscription {
        $sub = $this->getOrCreateSubscription($app);

        if ($sub->isSuspended()) {
            throw new \LogicException('This subscription is suspended. Please contact support.');
        }
        // A trial of the same plan can be converted to a paid subscription
        if (!$sub->isUpgrade($targetPlan) && !($sub->isTrial() && $sub->current_plan === $targetPlan)) {
            throw new \InvalidArgumentException("Cannot upgrade from {$sub->current_plan} to {$targetPlan}.");
        }

        $before = $this->snapshot($sub);

        DB::transaction(function () use ($sub, $app, $targetPlan, $payment, $actor, $billingPeriod, $before) {
            $sub->update([
                'current_plan'         => $targetPlan,
                'pending_plan'         => null,
                'status'               => 'active',
                'billing_period'       => $billingPeriod,
                'billing_cycle_start'  => today(),
                'billing_cycle_end'    => $this->cycleEnd(today(), $billingPeriod),
                'trial_ends_at'        => null,
                'grace_period_ends_at' => null,
                'canceled_at'          => null,
                'cancel_reason'        => null,
                'last_payment_at'      => now(),
            ]);
            $app->update(['plan' => $targetPlan]);

            $change = $this->planChange($sub, $before['plan'], $targetPlan, 'upgrade', $actor?->id ?? $app->user_id,
                "Payment #{$payment->id} — {$payment->amount} TND", (float) $payment->amount);

            $this->downgradeService->applyUpgradeEffects($app->user_id, $targetPlan);

            $this->audit($sub, 'upgraded', $actor, 'seller', "Payment #{$payment->id} ({$payment->amount} TND, {$billingPeriod})", $before, $change->id);
        });

        return $sub->fresh();
    }

    /** Seller schedules a downgrade for the end of the billing cycle. */
    public function scheduleDowngrade(SellerApplication $app, string $targetPlan, ?User $actor = null, ?string $reason = null): SellerSubscription
    {
        $sub = $this->getOrCreateSubscription($app);

        if (!$sub->isDowngrade($targetPlan)) {
            throw new \InvalidArgumentException("Cannot downgrade from {$sub->current_plan} to {$targetPlan}.");
        }
        if ($sub->hasPendingDowngrade()) {
            throw new \LogicException("A downgrade to {$sub->pending_plan} is already scheduled.");
        }

        $before = $this->snapshot($sub);
        DB::transaction(function () use ($sub, $targetPlan, $actor, $reason, $app, $before) {
            $sub->update([
                'pending_plan' => $targetPlan,
                'status'       => 'canceled',   // "canceled" = runs to end, then downgrades
                'canceled_at'  => now(),
            ]);
            $change = $this->planChange($sub, $sub->current_plan, $targetPlan, 'downgrade', $actor?->id ?? $app->user_id,
                $reason ?? 'Seller requested downgrade', 0, $sub->billing_cycle_end ?? now());
            $this->audit($sub, 'downgrade_scheduled', $actor, 'seller', $reason ?? 'Seller requested downgrade', $before, $change->id);
        });

        return $sub->fresh();
    }

    public function cancelPendingDowngrade(SellerApplication $app, ?User $actor = null): SellerSubscription
    {
        $sub = $this->getOrCreateSubscription($app);
        if (!$sub->hasPendingDowngrade()) {
            throw new \LogicException('No pending downgrade to cancel.');
        }

        $before = $this->snapshot($sub);
        $sub->update(['pending_plan' => null, 'status' => 'active', 'canceled_at' => null, 'cancel_reason' => null]);
        $this->audit($sub, 'downgrade_cancelled', $actor, 'seller', null, $before);

        return $sub->fresh();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // ADMIN ACTIONS
    // ═════════════════════════════════════════════════════════════════════════

    /** Legacy entry point (force-plan endpoint). */
    public function adminForce(SellerApplication $app, string $targetPlan, User $admin, string $reason = 'Admin override'): SellerSubscription
    {
        return $this->assignPlan($this->getOrCreateSubscription($app), $targetPlan, $admin, $reason);
    }

    /**
     * Assign any plan immediately (upgrade or downgrade). Paid plans start a
     * new billing cycle ending on $endDate (or one billing period from today).
     */
    public function assignPlan(
        SellerSubscription $sub,
        string $targetPlan,
        User $admin,
        string $reason,
        string $billingPeriod = 'monthly',
        ?Carbon $endDate = null
    ): SellerSubscription {
        $plan = SubscriptionPlan::where('slug', $targetPlan)->firstOrFail();
        if ($plan->isArchived()) {
            throw new \InvalidArgumentException("The {$plan->name} plan is archived and can't be assigned.");
        }
        if ($sub->current_plan === $plan->slug && !$endDate && $sub->billing_period === $billingPeriod) {
            throw new \InvalidArgumentException("Seller is already on {$plan->name}.");
        }

        $from      = SubscriptionPlan::forSlug($sub->current_plan);
        $isUpgrade = $plan->isHigherThan($from);
        $before    = $this->snapshot($sub);
        $app       = $sub->sellerApplication;

        DB::transaction(function () use ($sub, $plan, $admin, $reason, $billingPeriod, $endDate, $isUpgrade, $before, $app) {
            $sub->update([
                'current_plan'         => $plan->slug,
                'pending_plan'         => null,
                'status'               => $sub->isSuspended() ? 'suspended' : 'active',
                'billing_period'       => $billingPeriod,
                'billing_cycle_start'  => $plan->isFree() ? null : today(),
                'billing_cycle_end'    => $plan->isFree() ? null : ($endDate ?? $this->cycleEnd(today(), $billingPeriod)),
                'trial_ends_at'        => null,
                'grace_period_ends_at' => null,
                'canceled_at'          => null,
                'cancel_reason'        => null,
                'admin_note'           => $reason,
            ]);
            $app?->update(['plan' => $plan->slug]);

            $change = $this->planChange($sub, $before['plan'], $plan->slug, 'admin_force', $admin->id, $reason);

            if ($app) {
                $isUpgrade
                    ? $this->downgradeService->applyUpgradeEffects($app->user_id, $plan->slug)
                    : $this->downgradeService->applyRippleEffects($app, $plan->slug);
            }

            $this->audit($sub, 'plan_assigned', $admin, 'admin', $reason, $before, $change->id);
        });

        $end = $sub->fresh()->billing_cycle_end;
        $this->notify($sub, 'plan_assigned', $end ? 'plan_assigned' : 'plan_assigned_open',
            ['plan' => $plan->name, 'end_date' => $end?->toDateString(), 'reason' => $reason]);

        return $sub->fresh();
    }

    /** Move the end of the current period (billing cycle or trial). */
    public function changeEndDate(SellerSubscription $sub, Carbon $newEnd, User $admin, string $reason): SellerSubscription
    {
        if (SubscriptionPlan::forSlug($sub->current_plan)->isFree() && !$sub->isTrial()) {
            throw new \InvalidArgumentException('The seller is on a free plan — there is no end date to change.');
        }
        if ($newEnd->lt(today())) {
            throw new \InvalidArgumentException('The new end date cannot be in the past.');
        }

        $before = $this->snapshot($sub);
        DB::transaction(function () use ($sub, $newEnd, $admin, $reason, $before) {
            $updates = ['billing_cycle_end' => $newEnd->toDateString()];
            if ($sub->isTrial()) $updates['trial_ends_at'] = $newEnd->copy()->endOfDay();
            // Moving the end date out of an expired window brings the seller back to active
            if (in_array($sub->status, ['grace_period', 'past_due'], true)) {
                $updates['status'] = 'active';
                $updates['grace_period_ends_at'] = null;
            }
            $sub->update($updates);
            $this->audit($sub, 'end_date_changed', $admin, 'admin', $reason, $before);
        });

        $this->notify($sub, 'end_date_changed', 'end_date_changed',
            ['end_date' => $newEnd->toDateString(), 'reason' => $reason]);

        return $sub->fresh();
    }

    /** Add free days to the current period. */
    public function grantFreeDays(SellerSubscription $sub, int $days, User $admin, string $reason): SellerSubscription
    {
        if (SubscriptionPlan::forSlug($sub->current_plan)->isFree() && !$sub->isTrial()) {
            throw new \InvalidArgumentException('Free days only apply to a paid plan or a trial. Assign a plan or start a trial instead.');
        }

        $from   = $sub->billing_cycle_end && $sub->billing_cycle_end->gte(today()) ? $sub->billing_cycle_end->copy() : today();
        $newEnd = $from->addDays($days);
        $before = $this->snapshot($sub);

        DB::transaction(function () use ($sub, $newEnd, $admin, $reason, $days, $before) {
            $updates = ['billing_cycle_end' => $newEnd->toDateString()];
            if ($sub->isTrial()) $updates['trial_ends_at'] = $newEnd->copy()->endOfDay();
            if (in_array($sub->status, ['grace_period', 'past_due'], true)) {
                $updates['status'] = 'active';
                $updates['grace_period_ends_at'] = null;
            }
            $sub->update($updates);
            $this->audit($sub, 'free_days_granted', $admin, 'admin', "+{$days} days — {$reason}", $before);
        });

        $this->notify($sub, 'free_days_granted', 'free_days_granted',
            ['days' => $days, 'end_date' => $newEnd->toDateString()]);

        return $sub->fresh();
    }

    /** Start a trial of $planSlug. At the end, without payment, the seller returns to the default plan. */
    public function startTrial(SellerSubscription $sub, string $planSlug, int $days, User $admin, string $reason): SellerSubscription
    {
        $plan = SubscriptionPlan::where('slug', $planSlug)->firstOrFail();
        if ($plan->isArchived() || $plan->isFree()) {
            throw new \InvalidArgumentException('Trials are only available for active paid plans.');
        }
        if ($sub->isSuspended()) {
            throw new \LogicException('Reactivate the subscription before starting a trial.');
        }

        $before = $this->snapshot($sub);
        $app    = $sub->sellerApplication;
        $endsAt = now()->addDays($days)->endOfDay();

        DB::transaction(function () use ($sub, $plan, $days, $admin, $reason, $before, $app, $endsAt) {
            $sub->update([
                'current_plan'         => $plan->slug,
                'pending_plan'         => null,
                'status'               => 'trial',
                'billing_cycle_start'  => today(),
                'billing_cycle_end'    => $endsAt->toDateString(),
                'trial_ends_at'        => $endsAt,
                'grace_period_ends_at' => null,
                'canceled_at'          => null,
                'admin_note'           => $reason,
            ]);
            $app?->update(['plan' => $plan->slug]);

            $change = $this->planChange($sub, $before['plan'], $plan->slug, 'admin_force', $admin->id, "Trial ({$days} days) — {$reason}");
            if ($app) $this->downgradeService->applyUpgradeEffects($app->user_id, $plan->slug);

            $this->audit($sub, 'trial_started', $admin, 'admin', "{$days}-day trial — {$reason}", $before, $change->id);
        });

        $this->notify($sub, 'trial_started', 'trial_started',
            ['plan' => $plan->name, 'days' => $days, 'end_date' => $endsAt->toDateString()]);

        return $sub->fresh();
    }

    public function suspend(SellerSubscription|SellerApplication $target, User $admin, string $reason = 'Admin suspension'): SellerSubscription
    {
        $sub = $target instanceof SellerApplication ? $this->getOrCreateSubscription($target) : $target;
        if ($sub->isSuspended()) {
            throw new \LogicException('Subscription is already suspended.');
        }

        $before = $this->snapshot($sub);
        DB::transaction(function () use ($sub, $admin, $reason, $before) {
            $sub->update([
                'status'       => 'suspended',
                'suspended_at' => now(),
                'suspended_by' => $admin->id,
                'admin_note'   => $reason,
            ]);
            // Sponsorships are paused (restored on reactivation), never deleted
            $this->downgradeService->pauseSponsorships($sub->user_id, 0);
            $this->audit($sub, 'suspended', $admin, 'admin', $reason, $before);
        });

        $this->notify($sub, 'suspended', 'suspended', ['reason' => $reason], ['icon' => 'alert-triangle']);

        return $sub->fresh();
    }

    public function reactivate(SellerSubscription $sub, User $admin, string $reason): SellerSubscription
    {
        if (!$sub->isSuspended()) {
            throw new \LogicException('Subscription is not suspended.');
        }

        $before = $this->snapshot($sub);
        DB::transaction(function () use ($sub, $admin, $reason, $before) {
            $status = 'active';
            if ($sub->trial_ends_at && $sub->trial_ends_at->isFuture()) {
                $status = 'trial';
            } elseif ($sub->billing_cycle_end && $sub->billing_cycle_end->lt(today())) {
                // Period ran out while suspended — the scheduler's grace logic takes over
                $status = 'grace_period';
            }
            $sub->update([
                'status'               => $status,
                'grace_period_ends_at' => $status === 'grace_period'
                    ? now()->addDays(SellerSubscription::GRACE_PERIOD_DAYS) : $sub->grace_period_ends_at,
                'suspended_at'         => null,
                'suspended_by'         => null,
                'admin_note'           => $reason,
            ]);
            if (SubscriptionPlan::forSlug($sub->current_plan)->hasFeature('sponsorships')) {
                $this->downgradeService->resumeSponsorships($sub->user_id);
            }
            $this->audit($sub, 'reactivated', $admin, 'admin', $reason, $before);
        });

        $this->notify($sub, 'reactivated', 'reactivated', ['reason' => $reason]);

        return $sub->fresh();
    }

    /**
     * Cancel a subscription. Immediate: back to the default plan now.
     * Otherwise it runs to the end of the period, then moves to the default plan.
     */
    public function cancel(SellerSubscription $sub, User $admin, string $reason, bool $immediate = false): SellerSubscription
    {
        $default = SubscriptionPlan::defaultPlan();
        $plan    = SubscriptionPlan::forSlug($sub->current_plan);

        if ($plan->slug === $default->slug && !$sub->isTrial()) {
            throw new \InvalidArgumentException("The seller is already on the default plan ({$default->name}).");
        }

        $before = $this->snapshot($sub);
        $app    = $sub->sellerApplication;
        $runsUntil = $sub->billing_cycle_end;

        DB::transaction(function () use ($sub, $admin, $reason, $immediate, $default, $before, $app, $runsUntil) {
            if ($immediate || !$runsUntil || $runsUntil->lt(today())) {
                $sub->update([
                    'current_plan'         => $default->slug,
                    'pending_plan'         => null,
                    'status'               => 'canceled',
                    'billing_cycle_start'  => null,
                    'billing_cycle_end'    => null,
                    'trial_ends_at'        => null,
                    'grace_period_ends_at' => null,
                    'canceled_at'          => now(),
                    'cancel_reason'        => $reason,
                ]);
                $app?->update(['plan' => $default->slug]);
                $change = $this->planChange($sub, $before['plan'], $default->slug, 'admin_force', $admin->id, "Cancelled — {$reason}");
                if ($app) $this->downgradeService->applyRippleEffects($app, $default->slug);
                $this->audit($sub, 'cancelled', $admin, 'admin', "Immediate — {$reason}", $before, $change->id);
            } else {
                $sub->update([
                    'pending_plan'  => $default->slug,
                    'status'        => 'canceled',
                    'canceled_at'   => now(),
                    'cancel_reason' => $reason,
                ]);
                $this->audit($sub, 'cancelled', $admin, 'admin', "At period end ({$runsUntil->format('Y-m-d')}) — {$reason}", $before);
            }
        });

        $fresh = $sub->fresh();
        $this->notify($sub, 'cancelled', $fresh->pending_plan ? 'cancelled_at_end' : 'cancelled_now',
            ['plan' => $default->name, 'end_date' => $fresh->billing_cycle_end?->toDateString(), 'reason' => $reason],
            ['icon' => 'alert-triangle']);

        return $fresh;
    }

    public function setCommissionOverride(SellerSubscription $sub, float $rate, ?Carbon $expiresAt, User $admin, string $reason): SellerSubscription
    {
        $before = $this->snapshot($sub);
        DB::transaction(function () use ($sub, $rate, $expiresAt, $admin, $reason, $before) {
            $sub->update([
                'commission_override'            => $rate,
                'commission_override_expires_at' => $expiresAt?->copy()->endOfDay(),
                'commission_override_reason'     => $reason,
                'commission_override_set_by'     => $admin->id,
            ]);
            $this->audit($sub, 'commission_override_set', $admin, 'admin', $reason, $before);
        });

        $rateLabel = rtrim(rtrim(number_format($rate, 2), '0'), '.');
        $this->notify($sub, 'commission_override_set', $expiresAt ? 'commission_set_until' : 'commission_set',
            ['rate' => $rateLabel, 'end_date' => $expiresAt?->toDateString()]);

        return $sub->fresh();
    }

    public function removeCommissionOverride(SellerSubscription $sub, User $admin, string $reason): SellerSubscription
    {
        if ($sub->commission_override === null) {
            throw new \LogicException('This seller has no commission override.');
        }

        $before = $this->snapshot($sub);
        DB::transaction(function () use ($sub, $admin, $reason, $before) {
            $this->clearOverride($sub);
            $this->audit($sub, 'commission_override_removed', $admin, 'admin', $reason, $before);
        });

        $this->notify($sub, 'commission_override_removed', 'commission_removed');

        return $sub->fresh();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // SCHEDULER (subscriptions:process)
    // ═════════════════════════════════════════════════════════════════════════

    /** Reminders N days before a paid period or trial ends. Deduplicated per sub/date/day-count. */
    public function sendExpiryReminders(array $days = [7, 1]): int
    {
        $sent = 0;
        foreach ($days as $n) {
            $target = today()->addDays($n)->toDateString();

            SellerSubscription::whereIn('status', ['active', 'trial', 'canceled'])
                ->where('billing_cycle_end', $target)
                ->with('user')
                ->get()
                ->each(function (SellerSubscription $sub) use ($n, &$sent) {
                    $plan = SubscriptionPlan::forSlug($sub->current_plan);
                    if ($plan->isFree() && !$sub->isTrial()) return;

                    $already = SubscriptionAuditLog::where('seller_subscription_id', $sub->id)
                        ->where('action', 'expiry_reminder')
                        ->whereDate('created_at', today())
                        ->where('reason', "{$n}d")
                        ->exists();
                    if ($already) return;

                    $params = ['plan' => $plan->name, 'days' => $n, 'end_date' => $sub->billing_cycle_end->toDateString()];
                    if ($sub->isTrial()) {
                        $key = 'reminder_trial';
                    } elseif ($sub->hasPendingDowngrade()) {
                        $key = 'reminder_downgrade';
                        $params['to'] = SubscriptionPlan::forSlug($sub->pending_plan)->name;
                    } else {
                        $key = $sub->billing_period === 'yearly' ? 'reminder_renew_yearly' : 'reminder_renew_monthly';
                        $params['price'] = number_format($plan->priceFor($sub->billing_period ?? 'monthly'), 0);
                    }

                    $this->notify($sub, 'expiry_reminder', $key, $params,
                        ['source' => 'subscription_renewal_reminder', 'days_remaining' => $n]);
                    $this->audit($sub, 'expiry_reminder', null, 'system', "{$n}d");
                    $sent++;
                });
        }
        return $sent;
    }

    /** Trials whose end has passed → default plan. */
    public function processExpiredTrials(): int
    {
        $count = 0;
        SellerSubscription::where('status', 'trial')
            ->where('trial_ends_at', '<=', now())
            ->with('sellerApplication')
            ->get()
            ->each(function (SellerSubscription $sub) use (&$count) {
                try {
                    $plan = SubscriptionPlan::forSlug($sub->current_plan)->name;
                    $this->revertToDefault($sub, 'trial_ended', 'trial_end', "Trial of {$plan} ended without payment");
                    $count++;
                } catch (\Throwable $e) {
                    Log::error("[SubscriptionService] processExpiredTrials failed for sub #{$sub->id}: " . $e->getMessage());
                }
            });
        return $count;
    }

    /**
     * Paid periods that ended: apply scheduled downgrades / cancellations, or
     * start the grace period when nothing was paid.
     */
    public function processExpiredCycles(): int
    {
        $count = 0;
        SellerSubscription::whereIn('status', ['active', 'canceled'])
            ->whereNotNull('billing_cycle_end')
            ->where('billing_cycle_end', '<', today()->toDateString())
            ->with('sellerApplication')
            ->get()
            ->each(function (SellerSubscription $sub) use (&$count) {
                try {
                    $this->applyEndOfCycle($sub);
                    $count++;
                } catch (\Throwable $e) {
                    Log::error("[SubscriptionService] processExpiredCycles failed for sub #{$sub->id}: " . $e->getMessage());
                }
            });
        return $count;
    }

    private function applyEndOfCycle(SellerSubscription $sub): void
    {
        $app = $sub->sellerApplication;
        if (!$app) return;

        if ($sub->hasPendingDowngrade()) {
            $target = SubscriptionPlan::forSlug($sub->pending_plan);
            $before = $this->snapshot($sub);

            DB::transaction(function () use ($sub, $app, $target, $before) {
                $sub->update([
                    'current_plan'        => $target->slug,
                    'pending_plan'        => null,
                    'status'              => $target->isFree() ? 'expired' : 'active',
                    'billing_cycle_start' => $target->isFree() ? null : today(),
                    'billing_cycle_end'   => $target->isFree() ? null : $this->cycleEnd(today(), $sub->billing_period ?? 'monthly'),
                    'canceled_at'         => null,
                ]);
                $app->update(['plan' => $target->slug]);
                $change = $this->planChange($sub, $before['plan'], $target->slug, 'downgrade', null,
                    'Scheduled change applied at end of billing cycle');
                $this->downgradeService->applyRippleEffects($app, $target->slug);
                $this->audit($sub, 'downgraded', null, 'system', 'Scheduled change applied at end of billing cycle', $before, $change->id);
            });

            $this->notify($sub, 'downgraded', 'downgraded', ['plan' => $target->name]);
            return;
        }

        // No payment for renewal — grace period
        $graceEndsAt = Carbon::parse($sub->billing_cycle_end)->addDays(SellerSubscription::GRACE_PERIOD_DAYS)->endOfDay();
        $before = $this->snapshot($sub);
        $sub->update(['status' => 'grace_period', 'grace_period_ends_at' => $graceEndsAt]);
        $this->audit($sub, 'grace_started', null, 'system', 'Billing period ended without renewal', $before);

        $plan = SubscriptionPlan::forSlug($sub->current_plan)->name;
        $this->notify($sub, 'grace_started', 'grace_started',
            ['plan' => $plan, 'end_date' => $graceEndsAt->toDateString(), 'to' => SubscriptionPlan::defaultPlan()->name],
            ['icon' => 'alert-triangle']);
    }

    /** Grace periods that ran out → default plan. */
    public function processExpiredGrace(): int
    {
        $count = 0;
        SellerSubscription::graceExpired()
            ->with('sellerApplication')
            ->get()
            ->each(function (SellerSubscription $sub) use (&$count) {
                try {
                    $this->revertToDefault($sub, 'expired', 'payment_failure', 'Payment not received — grace period expired');
                    $count++;
                } catch (\Throwable $e) {
                    Log::error("[SubscriptionService] processExpiredGrace failed for sub #{$sub->id}: " . $e->getMessage());
                }
            });
        return $count;
    }

    /** Clear commission overrides whose expiry passed (they already stopped applying). */
    public function processExpiredOverrides(): int
    {
        $count = 0;
        SellerSubscription::whereNotNull('commission_override')
            ->whereNotNull('commission_override_expires_at')
            ->where('commission_override_expires_at', '<=', now())
            ->get()
            ->each(function (SellerSubscription $sub) use (&$count) {
                $before = $this->snapshot($sub);
                $this->clearOverride($sub);
                $this->audit($sub, 'commission_override_expired', null, 'system', null, $before);
                $this->notify($sub, 'commission_override_expired', 'commission_expired');
                $count++;
            });
        return $count;
    }

    private function revertToDefault(SellerSubscription $sub, string $auditAction, string $changeType, string $reason): void
    {
        $app     = $sub->sellerApplication;
        $default = SubscriptionPlan::defaultPlan();
        $before  = $this->snapshot($sub);

        DB::transaction(function () use ($sub, $app, $default, $auditAction, $changeType, $reason, $before) {
            $sub->update([
                'current_plan'         => $default->slug,
                'pending_plan'         => null,
                'status'               => 'expired',
                'billing_cycle_start'  => null,
                'billing_cycle_end'    => null,
                'trial_ends_at'        => null,
                'grace_period_ends_at' => null,
            ]);
            $app?->update(['plan' => $default->slug]);
            $change = $this->planChange($sub, $before['plan'], $default->slug, $changeType, null, $reason);
            if ($app) $this->downgradeService->applyRippleEffects($app, $default->slug);
            $this->audit($sub, $auditAction, null, 'system', $reason, $before, $change->id);
        });

        $this->notify($sub, $auditAction, $auditAction === 'trial_ended' ? 'reverted_trial' : 'reverted_unpaid',
            ['plan' => $default->name, 'from' => SubscriptionPlan::forSlug($before['plan'] ?? null)->name],
            ['icon' => 'alert-triangle']);
        Log::info("[SubscriptionService] Reverted to default: user #{$sub->user_id} — {$reason}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNALS
    // ─────────────────────────────────────────────────────────────────────────

    public function cycleEnd(Carbon $start, string $period): Carbon
    {
        return $period === 'yearly' ? $start->copy()->addYear() : $start->copy()->addDays(30);
    }

    private function clearOverride(SellerSubscription $sub): void
    {
        $sub->update([
            'commission_override'            => null,
            'commission_override_expires_at' => null,
            'commission_override_reason'     => null,
            'commission_override_set_by'     => null,
        ]);
    }

    private function planChange(SellerSubscription $sub, string $from, string $to, string $type, ?int $by, ?string $reason, float $amount = 0, $effectiveAt = null): SubscriptionPlanChange
    {
        return SubscriptionPlanChange::create([
            'seller_subscription_id' => $sub->id,
            'changed_by_user_id'     => $by,
            'from_plan'              => $from,
            'to_plan'                => $to,
            'change_type'            => $type,
            'effective_at'           => $effectiveAt ?? now(),
            'reason'                 => $reason,
            'amount_charged'         => $amount,
        ]);
    }

    /** Fields worth diffing in the audit log. */
    private function snapshot(SellerSubscription $sub): array
    {
        return [
            'plan'                => $sub->current_plan,
            'pending_plan'        => $sub->pending_plan,
            'status'              => $sub->status,
            'billing_period'      => $sub->billing_period,
            'billing_cycle_end'   => optional($sub->billing_cycle_end)->toDateString(),
            'trial_ends_at'       => optional($sub->trial_ends_at)->toISOString(),
            'commission_override' => $sub->commission_override,
            'commission_override_expires_at' => optional($sub->commission_override_expires_at)->toISOString(),
        ];
    }

    public function audit(SellerSubscription $sub, string $action, ?User $actor, string $role, ?string $reason = null, ?array $before = null, ?int $planChangeId = null): void
    {
        try {
            $fresh = $sub->fresh() ?? $sub;
            SubscriptionAuditLog::create([
                'seller_subscription_id' => $sub->id,
                'seller_id'              => $sub->user_id,
                'subscription_plan_id'   => optional(SubscriptionPlan::where('slug', $fresh->current_plan)->first())->id,
                'actor_id'               => $actor?->id,
                'actor_role'             => $actor ? $role : 'system',
                'action'                 => $action,
                'reason'                 => $reason,
                'before'                 => $before,
                'after'                  => $this->snapshot($fresh),
                'plan_change_id'         => $planChangeId,
            ]);
        } catch (\Throwable $e) {
            Log::error('[SubscriptionService] audit failed: ' . $e->getMessage());
        }
    }

    /**
     * Notify the seller. $key picks seller.notif.subscription.{key}.title/body; the text is
     * rendered in the seller's own locale when the notification is stored.
     */
    private function notify(SellerSubscription $sub, string $action, string $key, array $params = [], array $meta = []): void
    {
        try {
            $user = $sub->user ?? User::find($sub->user_id);
            $user?->notify(new SubscriptionUpdatedNotification($action, $key, $params, $meta));
        } catch (\Throwable $e) {
            Log::warning('[SubscriptionService] notify failed: ' . $e->getMessage());
        }
    }

}
