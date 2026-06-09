<?php
// app/Models/SellerSubscription.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * SellerSubscription
 *
 * One row per approved seller. Tracks the full subscription lifecycle:
 * billing cycle, pending downgrades, grace periods, and status.
 *
 * IMPORTANT: seller_applications.plan is ALWAYS kept in sync with
 * current_plan here. SellerPlanMiddleware reads seller_applications.plan,
 * so we must write both on every plan change.
 *
 * @property int         $id
 * @property int         $seller_application_id
 * @property int         $user_id
 * @property string      $current_plan   free|red|black
 * @property string|null $pending_plan   NULL or free|red|black
 * @property string      $status         active|grace_period|past_due|canceled|expired|suspended
 * @property string|null $billing_cycle_start
 * @property string|null $billing_cycle_end
 * @property Carbon|null $grace_period_ends_at
 * @property Carbon|null $canceled_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $last_payment_at
 * @property int|null    $suspended_by
 * @property string|null $admin_note
 */
class SellerSubscription extends Model
{
    use HasFactory;

    protected $table = 'seller_subscriptions';

    protected $fillable = [
        'seller_application_id',
        'user_id',
        'current_plan',
        'pending_plan',
        'status',
        'billing_cycle_start',
        'billing_cycle_end',
        'grace_period_ends_at',
        'canceled_at',
        'suspended_at',
        'last_payment_at',
        'suspended_by',
        'admin_note',
    ];

    protected $casts = [
        'grace_period_ends_at' => 'datetime',
        'canceled_at'          => 'datetime',
        'suspended_at'         => 'datetime',
        'last_payment_at'      => 'datetime',
        'billing_cycle_start'  => 'date',
        'billing_cycle_end'    => 'date',
    ];

    // ── Plan tier hierarchy (used for upgrade/downgrade comparisons) ──────────

    public const PLAN_TIERS = ['free' => 0, 'red' => 1, 'black' => 2];

    public const GRACE_PERIOD_DAYS = 7;   // How long after billing_cycle_end features stay on

    // ── Relationships ─────────────────────────────────────────────────────────

    public function sellerApplication()
    {
        return $this->belongsTo(SellerApplication::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function planChanges()
    {
        return $this->hasMany(SubscriptionPlanChange::class)
                    ->orderByDesc('created_at');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($q)          { return $q->where('status', 'active'); }
    public function scopeGracePeriod($q)     { return $q->where('status', 'grace_period'); }
    public function scopePaidPlan($q)        { return $q->whereIn('current_plan', ['red', 'black']); }
    public function scopePendingDowngrade($q){ return $q->whereNotNull('pending_plan'); }

    /** Subscriptions whose billing cycle has ended (scheduler uses this) */
    public function scopeCycleExpiredToday($q)
    {
        return $q->where('status', 'active')
                 ->whereNotNull('billing_cycle_end')
                 ->where('billing_cycle_end', '<=', now()->toDateString());
    }

    /** Grace periods that have now fully expired (scheduler uses this) */
    public function scopeGraceExpired($q)
    {
        return $q->where('status', 'grace_period')
                 ->where('grace_period_ends_at', '<=', now());
    }

    // ── Plan tier helpers ─────────────────────────────────────────────────────

    public function currentTier(): int
    {
        return self::PLAN_TIERS[$this->current_plan] ?? 0;
    }

    public function pendingTier(): int
    {
        return self::PLAN_TIERS[$this->pending_plan ?? $this->current_plan] ?? 0;
    }

    public function isUpgrade(string $targetPlan): bool
    {
        return (self::PLAN_TIERS[$targetPlan] ?? 0) > $this->currentTier();
    }

    public function isDowngrade(string $targetPlan): bool
    {
        return (self::PLAN_TIERS[$targetPlan] ?? 0) < $this->currentTier();
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isActive(): bool       { return $this->status === 'active'; }
    public function isGrace(): bool        { return $this->status === 'grace_period'; }
    public function isSuspended(): bool    { return $this->status === 'suspended'; }
    public function isCanceled(): bool     { return $this->status === 'canceled'; }
    public function hasFeatureAccess(): bool
    {
        // Seller keeps features during active + grace + canceled (until cycle end)
        return in_array($this->status, ['active', 'grace_period', 'canceled']);
    }

    public function hasPendingDowngrade(): bool
    {
        return ! is_null($this->pending_plan);
    }

    // ── Max products for current plan ─────────────────────────────────────────

    public function maxProducts(): ?int
    {
        return match($this->current_plan) {
            'free'  => 30,
            'red'   => 150,
            'black' => null,  // unlimited
            default => 30,
        };
    }

    // ── Days remaining in billing cycle ───────────────────────────────────────

    public function daysRemainingInCycle(): int
    {
        if (! $this->billing_cycle_end) return 0;
        return max(0, now()->startOfDay()->diffInDays($this->billing_cycle_end, false));
    }

    // ── Prorated amount for upgrade mid-cycle ─────────────────────────────────

    public function proratedUpgradeAmount(string $targetPlan): float
    {
        $planPrices = ['red' => 49.0, 'black' => 129.0];
        $targetPrice  = $planPrices[$targetPlan]  ?? 0;
        $currentPrice = $planPrices[$this->current_plan] ?? 0;

        if (! $this->billing_cycle_end || ! $this->billing_cycle_start) {
            return $targetPrice;
        }

        $totalDays     = max(1, (int) $this->billing_cycle_start->diffInDays($this->billing_cycle_end));
        $daysRemaining = $this->daysRemainingInCycle();
        $fraction      = $daysRemaining / $totalDays;

        $cost = ($targetPrice - $currentPrice) * $fraction;
        return max(0, round($cost, 3));
    }

    // ── Human-readable status label ───────────────────────────────────────────

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'active'       => 'Active',
            'grace_period' => 'Grace Period',
            'past_due'     => 'Payment Overdue',
            'canceled'     => 'Canceled (runs to end of period)',
            'expired'      => 'Expired',
            'suspended'    => 'Suspended by Admin',
            default        => $this->status,
        };
    }
}