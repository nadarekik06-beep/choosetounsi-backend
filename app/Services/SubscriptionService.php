<?php
// app/Services/SubscriptionService.php

namespace App\Services;

use App\Models\SellerApplication;
use App\Models\SellerSubscription;
use App\Models\SubscriptionPlanChange;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * SubscriptionService
 *
 * Single source of truth for ALL plan change logic on ChooseTounsi.
 *
 * Public API:
 *   getOrCreateSubscription(SellerApplication)  → SellerSubscription
 *   upgrade(SellerApplication, plan, payment, User)   → SellerSubscription
 *   scheduleDowngrade(SellerApplication, plan, User)  → SellerSubscription
 *   cancelPendingDowngrade(SellerApplication, User)   → SellerSubscription
 *   adminForce(SellerApplication, plan, admin, reason) → SellerSubscription
 *   processExpiredCycles()   → int  (called by scheduler)
 *   processExpiredGrace()    → int  (called by scheduler)
 *
 * INVARIANT: every plan change writes BOTH seller_applications.plan AND
 * seller_subscriptions.current_plan so existing middleware keeps working.
 */
class SubscriptionService
{
    public function __construct(
        private PlanDowngradeService $downgradeService
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET OR CREATE SUBSCRIPTION ROW
    // Called on every request that needs subscription data.
    // Idempotent — safe to call multiple times.
    // ─────────────────────────────────────────────────────────────────────────

    public function getOrCreateSubscription(SellerApplication $app): SellerSubscription
    {
        $sub = SellerSubscription::where('seller_application_id', $app->id)->first();

        if ($sub) return $sub;

        return SellerSubscription::create([
            'seller_application_id' => $app->id,
            'user_id'               => $app->user_id,
            'current_plan'          => $app->plan ?? 'free',
            'pending_plan'          => null,
            'status'                => 'active',
            'billing_cycle_start'   => $app->plan !== 'free' ? today() : null,
            'billing_cycle_end'     => $app->plan !== 'free' ? today()->addDays(30) : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPGRADE — immediate effect
    // Called when seller pays for a higher plan.
    // ─────────────────────────────────────────────────────────────────────────

    public function upgrade(
        SellerApplication $app,
        string $targetPlan,
        SubscriptionPayment $payment,
        ?User $actor = null
    ): SellerSubscription {

        $sub = $this->getOrCreateSubscription($app);

        if (! $sub->isUpgrade($targetPlan)) {
            throw new \InvalidArgumentException(
                "Cannot upgrade from {$sub->current_plan} to {$targetPlan}."
            );
        }

        $fromPlan = $sub->current_plan;

        DB::transaction(function () use ($sub, $app, $targetPlan, $fromPlan, $payment, $actor) {

            // Calculate new billing cycle
            $cycleStart = today();
            $cycleEnd   = today()->addDays(30);

            // 1. Update subscription row
            $sub->update([
                'current_plan'        => $targetPlan,
                'pending_plan'        => null,        // cancel any pending downgrade
                'status'              => 'active',
                'billing_cycle_start' => $cycleStart,
                'billing_cycle_end'   => $cycleEnd,
                'grace_period_ends_at'=> null,
                'canceled_at'         => null,
                'last_payment_at'     => now(),
            ]);

            // 2. Mirror on seller_applications (keeps SellerPlanMiddleware working)
            $app->update(['plan' => $targetPlan]);

            // 3. Write audit log
            SubscriptionPlanChange::create([
                'seller_subscription_id' => $sub->id,
                'changed_by_user_id'     => $actor?->id ?? $app->user_id,
                'from_plan'              => $fromPlan,
                'to_plan'                => $targetPlan,
                'change_type'            => 'upgrade',
                'effective_at'           => now(),
                'reason'                 => "Payment #{$payment->id} — {$payment->amount} TND",
                'amount_charged'         => $payment->amount,
            ]);

            // 4. Re-activate any products soft-hidden from a previous downgrade
            DB::table('products')
                ->where('seller_id', $app->user_id)
                ->where('hidden_reason', 'over_plan_limit')
                ->whereNull('deleted_at')
                ->update(['hidden_reason' => null, 'is_active' => true]);

            // 5. Resume paused sponsorships (they were paused due to plan downgrade)
            DB::table('sponsorships')
                ->where('seller_id', $app->user_id)
                ->where('paused_reason', 'plan_downgrade')
                ->update(['status' => 'active', 'paused_reason' => null, 'paused_at' => null]);

            // 6. Resume paused promotions
            DB::table('promotions')
                ->where('seller_id', $app->user_id)
                ->where('paused_reason', 'plan_downgrade')
                ->where('ends_at', '>', now())  // only if not already expired
                ->update(['status' => 'active', 'paused_reason' => null, 'paused_at' => null]);

            Log::info("[SubscriptionService] Upgrade: user #{$app->user_id} {$fromPlan} → {$targetPlan}");
        });

        return $sub->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SCHEDULE DOWNGRADE — deferred to end of billing cycle
    // The seller keeps their current plan features until billing_cycle_end.
    // ─────────────────────────────────────────────────────────────────────────

    public function scheduleDowngrade(
        SellerApplication $app,
        string $targetPlan,
        ?User $actor = null,
        ?string $reason = null
    ): SellerSubscription {

        $sub = $this->getOrCreateSubscription($app);

        if (! $sub->isDowngrade($targetPlan)) {
            throw new \InvalidArgumentException(
                "Cannot downgrade from {$sub->current_plan} to {$targetPlan}."
            );
        }

        if ($sub->hasPendingDowngrade()) {
            throw new \LogicException(
                "A downgrade to {$sub->pending_plan} is already scheduled."
            );
        }

        $sub->update([
            'pending_plan' => $targetPlan,
            'status'       => 'canceled',  // "canceled" = runs to end, then downgrades
            'canceled_at'  => now(),
        ]);

        // Write audit log (effective_at = billing_cycle_end)
        SubscriptionPlanChange::create([
            'seller_subscription_id' => $sub->id,
            'changed_by_user_id'     => $actor?->id ?? $app->user_id,
            'from_plan'              => $sub->current_plan,
            'to_plan'                => $targetPlan,
            'change_type'            => 'downgrade',
            'effective_at'           => $sub->billing_cycle_end ?? now(),
            'reason'                 => $reason ?? 'Seller requested downgrade',
            'amount_charged'         => 0,
        ]);

        Log::info("[SubscriptionService] Downgrade scheduled: user #{$app->user_id} {$sub->current_plan} → {$targetPlan} at {$sub->billing_cycle_end}");

        return $sub->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CANCEL PENDING DOWNGRADE
    // Seller changes their mind before the cycle ends.
    // ─────────────────────────────────────────────────────────────────────────

    public function cancelPendingDowngrade(
        SellerApplication $app,
        ?User $actor = null
    ): SellerSubscription {

        $sub = $this->getOrCreateSubscription($app);

        if (! $sub->hasPendingDowngrade()) {
            throw new \LogicException('No pending downgrade to cancel.');
        }

        $sub->update([
            'pending_plan' => null,
            'status'       => 'active',
            'canceled_at'  => null,
        ]);

        Log::info("[SubscriptionService] Pending downgrade cancelled: user #{$app->user_id}");

        return $sub->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN FORCE PLAN CHANGE — immediate, bypasses billing cycle
    // ─────────────────────────────────────────────────────────────────────────

    public function adminForce(
        SellerApplication $app,
        string $targetPlan,
        User $admin,
        string $reason = 'Admin override'
    ): SellerSubscription {

        $sub      = $this->getOrCreateSubscription($app);
        $fromPlan = $sub->current_plan;

        if ($fromPlan === $targetPlan) {
            throw new \InvalidArgumentException("Seller is already on {$targetPlan}.");
        }

        DB::transaction(function () use ($sub, $app, $targetPlan, $fromPlan, $admin, $reason) {

            $isUpgrade = (SellerSubscription::PLAN_TIERS[$targetPlan] ?? 0) >
                         (SellerSubscription::PLAN_TIERS[$fromPlan] ?? 0);

            $updates = [
                'current_plan' => $targetPlan,
                'pending_plan' => null,
                'status'       => 'active',
                'canceled_at'  => null,
                'admin_note'   => $reason,
            ];

            if ($isUpgrade) {
                $updates['billing_cycle_start'] = today();
                $updates['billing_cycle_end']   = today()->addDays(30);
            } elseif ($targetPlan === 'free') {
                // Forced to free — no billing cycle
                $updates['billing_cycle_start'] = null;
                $updates['billing_cycle_end']   = null;
            }

            $sub->update($updates);

            // Mirror on seller_applications
            $app->update(['plan' => $targetPlan]);

            // Write audit log
            SubscriptionPlanChange::create([
                'seller_subscription_id' => $sub->id,
                'changed_by_user_id'     => $admin->id,
                'from_plan'              => $fromPlan,
                'to_plan'                => $targetPlan,
                'change_type'            => 'admin_force',
                'effective_at'           => now(),
                'reason'                 => $reason,
                'amount_charged'         => 0,
            ]);

            // Apply ripple effects if this is a downgrade
            if (! $isUpgrade) {
                $this->downgradeService->applyRippleEffects($app, $targetPlan);
            } else {
                // Upgrade: re-activate anything paused
                DB::table('products')
                    ->where('seller_id', $app->user_id)
                    ->where('hidden_reason', 'over_plan_limit')
                    ->whereNull('deleted_at')
                    ->update(['hidden_reason' => null, 'is_active' => true]);

                DB::table('sponsorships')
                    ->where('seller_id', $app->user_id)
                    ->where('paused_reason', 'plan_downgrade')
                    ->update(['status' => 'active', 'paused_reason' => null, 'paused_at' => null]);

                DB::table('promotions')
                    ->where('seller_id', $app->user_id)
                    ->where('paused_reason', 'plan_downgrade')
                    ->where('ends_at', '>', now())
                    ->update(['status' => 'active', 'paused_reason' => null, 'paused_at' => null]);
            }

            Log::info("[SubscriptionService] Admin force: user #{$app->user_id} {$fromPlan} → {$targetPlan} by admin #{$admin->id}");
        });

        return $sub->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SUSPEND — admin action, immediate
    // ─────────────────────────────────────────────────────────────────────────

    public function suspend(
        SellerApplication $app,
        User $admin,
        string $reason = 'Admin suspension'
    ): SellerSubscription {

        $sub = $this->getOrCreateSubscription($app);

        $sub->update([
            'status'       => 'suspended',
            'suspended_at' => now(),
            'suspended_by' => $admin->id,
            'admin_note'   => $reason,
        ]);

        // Suspend all sponsorships immediately
        DB::table('sponsorships')
            ->where('seller_id', $app->user_id)
            ->where('status', 'active')
            ->update(['status' => 'cancelled', 'paused_reason' => 'plan_downgrade', 'paused_at' => now()]);

        Log::info("[SubscriptionService] Suspended: user #{$app->user_id} by admin #{$admin->id}");

        return $sub->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROCESS EXPIRED CYCLES (called by SubscriptionSchedulerCommand daily)
    // Moves active subscriptions whose billing_cycle_end has passed into
    // grace_period and applies pending downgrades.
    // Returns count of subscriptions processed.
    // ─────────────────────────────────────────────────────────────────────────

    public function processExpiredCycles(): int
    {
        $count = 0;

        // Find all active/canceled subscriptions whose billing cycle has ended
        SellerSubscription::where('billing_cycle_end', '<=', now()->toDateString())
            ->whereIn('status', ['active', 'canceled'])
            ->whereNotNull('billing_cycle_end')
            ->whereIn('current_plan', ['red', 'black'])
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

    // ─────────────────────────────────────────────────────────────────────────
    // APPLY END OF CYCLE
    // Called when billing_cycle_end is reached.
    // ─────────────────────────────────────────────────────────────────────────

    private function applyEndOfCycle(SellerSubscription $sub): void
    {
        $app = $sub->sellerApplication;
        if (! $app) return;

        if ($sub->hasPendingDowngrade()) {
            // Seller had scheduled a downgrade — apply it now
            $targetPlan = $sub->pending_plan;

            DB::transaction(function () use ($sub, $app, $targetPlan) {
                $fromPlan = $sub->current_plan;

                $sub->update([
                    'current_plan'        => $targetPlan,
                    'pending_plan'        => null,
                    'status'              => $targetPlan === 'free' ? 'expired' : 'active',
                    'billing_cycle_start' => $targetPlan === 'free' ? null : today(),
                    'billing_cycle_end'   => $targetPlan === 'free' ? null : today()->addDays(30),
                    'canceled_at'         => null,
                ]);

                $app->update(['plan' => $targetPlan]);

                SubscriptionPlanChange::create([
                    'seller_subscription_id' => $sub->id,
                    'changed_by_user_id'     => null,
                    'from_plan'              => $fromPlan,
                    'to_plan'                => $targetPlan,
                    'change_type'            => 'downgrade',
                    'effective_at'           => now(),
                    'reason'                 => 'Scheduled downgrade applied at end of billing cycle',
                    'amount_charged'         => 0,
                ]);

                // Apply all ripple effects now that the downgrade is live
                $this->downgradeService->applyRippleEffects($app, $targetPlan);

                Log::info("[SubscriptionService] Scheduled downgrade applied: user #{$app->user_id} {$fromPlan} → {$targetPlan}");
            });

        } else {
            // No payment received for renewal — enter grace period
            $graceEndsAt = Carbon::parse($sub->billing_cycle_end)
                                 ->addDays(SellerSubscription::GRACE_PERIOD_DAYS);

            $sub->update([
                'status'               => 'grace_period',
                'grace_period_ends_at' => $graceEndsAt,
            ]);

            Log::info("[SubscriptionService] Grace period started: user #{$app->user_id} — ends {$graceEndsAt->toDateString()}");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROCESS EXPIRED GRACE PERIODS (called by scheduler daily)
    // Sellers in grace_period whose grace window has passed → revert to free.
    // ─────────────────────────────────────────────────────────────────────────

    public function processExpiredGrace(): int
    {
        $count = 0;

        SellerSubscription::graceExpired()
            ->with('sellerApplication')
            ->get()
            ->each(function (SellerSubscription $sub) use (&$count) {
                try {
                    $this->revertToFree($sub, 'Payment not received — grace period expired');
                    $count++;
                } catch (\Throwable $e) {
                    Log::error("[SubscriptionService] processExpiredGrace failed for sub #{$sub->id}: " . $e->getMessage());
                }
            });

        return $count;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REVERT TO FREE — used when grace period expires or payment permanently fails
    // ─────────────────────────────────────────────────────────────────────────

    private function revertToFree(SellerSubscription $sub, string $reason): void
    {
        $app = $sub->sellerApplication;
        if (! $app) return;

        $fromPlan = $sub->current_plan;

        DB::transaction(function () use ($sub, $app, $fromPlan, $reason) {
            $sub->update([
                'current_plan'        => 'free',
                'pending_plan'        => null,
                'status'              => 'expired',
                'billing_cycle_start' => null,
                'billing_cycle_end'   => null,
                'grace_period_ends_at'=> null,
            ]);

            $app->update(['plan' => 'free']);

            SubscriptionPlanChange::create([
                'seller_subscription_id' => $sub->id,
                'changed_by_user_id'     => null,
                'from_plan'              => $fromPlan,
                'to_plan'                => 'free',
                'change_type'            => 'payment_failure',
                'effective_at'           => now(),
                'reason'                 => $reason,
                'amount_charged'         => 0,
            ]);

            // Apply ripple effects for downgrade to free
            $this->downgradeService->applyRippleEffects($app, 'free');

            Log::info("[SubscriptionService] Reverted to free: user #{$app->user_id} — {$reason}");
        });
    }
}