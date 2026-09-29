<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A seller's prepaid ad wallet. Only App\Services\Ads\AdWalletService writes it
 * (always with a matching ad_wallet_transactions row).
 */
class AdWallet extends Model
{
    protected $fillable = ['seller_id', 'balance', 'credit_balance', 'credit_expires_at'];

    protected $casts = [
        'balance'           => 'decimal:3',
        'credit_balance'    => 'decimal:3',
        'credit_expires_at' => 'datetime',
    ];

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function transactions()
    {
        return $this->hasMany(AdWalletTransaction::class, 'seller_id', 'seller_id');
    }

    /** Free credit still spendable right now (0 once expired, even before the sweep). */
    public function usableCredit(): float
    {
        if ($this->credit_expires_at !== null && $this->credit_expires_at->isPast()) {
            return 0.0;
        }
        return (float) $this->credit_balance;
    }

    public function available(): float
    {
        return round((float) $this->balance + $this->usableCredit(), 3);
    }
}
