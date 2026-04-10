<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SellerApplication extends Model
{
    use HasFactory;

    protected $table = 'seller_applications';

    protected $fillable = [
        'user_id',
        'full_name',
        'phone_number',
        'business_name',
        'business_category',
        'business_description',
        'wilaya',
        'city',
        'profile_picture',
        'sample_images',
        'facebook_url',
        'instagram_url',
        'website_url',
        'status',
        // ── Plan columns (separate concerns) ────────────────────────────────
        'preferred_plan',   // What the user expressed interest in (green/red/black)
        'plan',             // Active subscription (free/red/black) — always 'free' at start
        // ── Review metadata ──────────────────────────────────────────────────
        'rejection_reason',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'sample_images' => 'array',
        'reviewed_at'   => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Human-readable label for preferred_plan.
     * Used in admin notifications and UI.
     */
    public function getPreferredPlanLabelAttribute(): string
    {
        return match ($this->preferred_plan) {
            'red'   => 'Red Pepper (49 DT/mo)',
            'black' => 'Black Pepper (129 DT/mo)',
            default => 'Green Pepper (Free)',
        };
    }

    /**
     * True when the user expressed interest in a paid plan.
     */
    public function wantsPaidPlan(): bool
    {
        return in_array($this->preferred_plan, ['red', 'black']);
    }
}