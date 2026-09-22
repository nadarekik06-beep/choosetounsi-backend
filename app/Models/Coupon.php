<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'seller_id', 'code', 'discount_type', 'discount_value',
        'min_order_amount', 'usage_limit', 'usage_limit_per_customer',
        'usage_count', 'is_active',
    ];

    protected $casts = [
        'discount_value'    => 'decimal:3',
        'min_order_amount'  => 'decimal:3',
        'is_active'         => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'coupon_products');
    }

    public function redemptions()
    {
        return $this->hasMany(CouponRedemption::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function hasUsesRemaining(): bool
    {
        return $this->usage_limit === null || $this->usage_count < $this->usage_limit;
    }

    public function getDiscountLabelAttribute(): string
    {
        return $this->discount_type === 'percentage'
            ? (int) $this->discount_value . '% OFF'
            : number_format((float) $this->discount_value, 3) . ' DT OFF';
    }
}
