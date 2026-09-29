<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A wallet top-up intent. pending → paid | failed | cancelled exactly once
 * (App\Services\Ads\AdWalletService::settleTopUp); a paid top-up has one wallet transaction.
 */
class AdTopUp extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_PAID      = 'paid';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'seller_id', 'amount', 'gateway', 'status', 'reference', 'meta', 'paid_at',
        'wallet_transaction_id', 'settled_by',
    ];

    protected $casts = [
        'amount'  => 'decimal:3',
        'meta'    => 'array',
        'paid_at' => 'datetime',
    ];

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function walletTransaction()
    {
        return $this->belongsTo(AdWalletTransaction::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
