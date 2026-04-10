<?php
// app/Models/SubscriptionPayment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    protected $fillable = [
        'user_id',
        'plan',
        'amount',
        'currency',
        'status',
        'card_last4',
        'cardholder_name',
        'stripe_payment_intent_id',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Human-readable plan label */
    public function getPlanLabelAttribute(): string
    {
        return match($this->plan) {
            'red'   => 'Red Pepper (49 DT/month)',
            'black' => 'Black Pepper (129 DT/month)',
            default => $this->plan,
        };
    }
}