<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A manual (WhatsApp) payment request: wallet top-up or plan upgrade.
 * pending → approved | rejected | cancelled exactly once, only through
 * App\Services\Payments\PaymentRequestService (row locked while deciding).
 */
class PaymentRequest extends Model
{
    public const TYPE_WALLET_TOPUP = 'wallet_topup';
    public const TYPE_PLAN_UPGRADE = 'plan_upgrade';
    public const TYPES = [self::TYPE_WALLET_TOPUP, self::TYPE_PLAN_UPGRADE];

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED];

    public const METHODS = ['d17', 'bank_transfer', 'cash', 'other'];

    protected $fillable = [
        'reference', 'type', 'source', 'status', 'seller_id', 'store_name', 'seller_name', 'seller_email',
        'seller_phone', 'wallet_balance', 'amount', 'current_plan', 'requested_plan', 'billing_period', 'message',
        'amount_received', 'payment_method', 'transaction_reference', 'admin_note', 'rejection_reason',
        'decided_by', 'decided_at', 'cancelled_at', 'ad_top_up_id', 'subscription_payment_id',
    ];

    protected $casts = [
        'amount'          => 'decimal:3',
        'amount_received' => 'decimal:3',
        'wallet_balance'  => 'decimal:3',
        'decided_at'      => 'datetime',
        'cancelled_at'    => 'datetime',
    ];

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function logs()
    {
        return $this->hasMany(PaymentRequestLog::class)->orderBy('id');
    }

    public function adTopUp()
    {
        return $this->belongsTo(AdTopUp::class);
    }

    public function subscriptionPayment()
    {
        return $this->belongsTo(SubscriptionPayment::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isTopUp(): bool
    {
        return $this->type === self::TYPE_WALLET_TOPUP;
    }
}
