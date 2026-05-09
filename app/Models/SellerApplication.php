<?php
// app/Models/SellerApplication.php

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
        'business_categories',
        'business_subcategories',   // ← NEW
        'business_description',
        'wilaya',
        'city',
        'profile_picture',
        'sample_images',
        'sample_captions',
        'facebook_url',
        'instagram_url',
        'website_url',
        'status',
        'preferred_plan',
        'plan',
        'pricing_range',
        'rejection_reason',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'sample_images'           => 'array',
        'sample_captions'         => 'array',
        'business_categories'     => 'array',
        'business_subcategories'  => 'array',   // ← NEW
        'reviewed_at'             => 'datetime',
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

    public function scopePending($query)  { return $query->where('status', 'pending'); }
    public function scopeApproved($query) { return $query->where('status', 'approved'); }
    public function scopeRejected($query) { return $query->where('status', 'rejected'); }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function getPreferredPlanLabelAttribute(): string
    {
        return match ($this->preferred_plan) {
            'red'   => 'Red Pepper (49 DT/mo)',
            'black' => 'Black Pepper (129 DT/mo)',
            default => 'Green Pepper (Free)',
        };
    }

    public function wantsPaidPlan(): bool
    {
        return in_array($this->preferred_plan, ['red', 'black']);
    }
}