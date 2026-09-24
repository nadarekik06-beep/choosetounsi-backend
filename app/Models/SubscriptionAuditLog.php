<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail for seller subscriptions and plans. */
class SubscriptionAuditLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'seller_subscription_id', 'seller_id', 'subscription_plan_id', 'actor_id', 'actor_role',
        'action', 'reason', 'before', 'after', 'plan_change_id',
    ];

    protected $casts = [
        'before'     => 'array',
        'after'      => 'array',
        'created_at' => 'datetime',
    ];

    public const LABELS = [
        'plan_assigned'        => 'Plan assigned',
        'upgraded'             => 'Upgraded',
        'downgrade_scheduled'  => 'Downgrade scheduled',
        'downgrade_cancelled'  => 'Scheduled downgrade cancelled',
        'downgraded'           => 'Downgraded',
        'end_date_changed'     => 'End date changed',
        'free_days_granted'    => 'Free days granted',
        'trial_started'        => 'Trial started',
        'trial_ended'          => 'Trial ended',
        'suspended'            => 'Suspended',
        'reactivated'          => 'Reactivated',
        'cancelled'            => 'Cancelled',
        'grace_started'        => 'Grace period started',
        'expired'              => 'Expired — moved to default plan',
        'commission_override_set'     => 'Commission override set',
        'commission_override_removed' => 'Commission override removed',
        'commission_override_expired' => 'Commission override expired',
        'expiry_reminder'      => 'Expiry reminder sent',
        'plan_created'         => 'Plan created',
        'plan_updated'         => 'Plan updated',
        'plan_archived'        => 'Plan archived',
        'plan_restored'        => 'Plan restored',
        'default_commission_updated' => 'Default commission updated',
    ];

    public function actor()        { return $this->belongsTo(User::class, 'actor_id'); }
    public function subscription() { return $this->belongsTo(SellerSubscription::class, 'seller_subscription_id'); }
    public function plan()         { return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id'); }

    public function getLabelAttribute(): string
    {
        return self::LABELS[$this->action] ?? ucfirst(str_replace('_', ' ', $this->action));
    }
}
