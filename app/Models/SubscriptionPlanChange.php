<?php
// app/Models/SubscriptionPlanChange.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SubscriptionPlanChange
 *
 * Immutable audit log. One row per plan change event.
 * Never updated after creation — append only.
 */
class SubscriptionPlanChange extends Model
{
    protected $table = 'subscription_plan_changes';

    // No updated_at on this table
    const UPDATED_AT = null;

    protected $fillable = [
        'seller_subscription_id',
        'changed_by_user_id',
        'from_plan',
        'to_plan',
        'change_type',
        'effective_at',
        'reason',
        'amount_charged',
    ];

    protected $casts = [
        'effective_at'  => 'datetime',
        'amount_charged'=> 'decimal:3',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function subscription()
    {
        return $this->belongsTo(SellerSubscription::class, 'seller_subscription_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getChangeTypeLabelAttribute(): string
    {
        return match($this->change_type) {
            'upgrade'         => 'Upgrade',
            'downgrade'       => 'Downgrade',
            'admin_force'     => 'Admin Override',
            'payment_failure' => 'Payment Failure',
            'trial_end'       => 'Trial Ended',
            'reactivation'    => 'Reactivation',
            default           => $this->change_type,
        };
    }
}