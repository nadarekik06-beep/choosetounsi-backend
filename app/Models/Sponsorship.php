<?php
// app/Models/Sponsorship.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Sponsorship model.
 *
 * One row = one sponsorship activation.
 * The fast-query flags (is_sponsored, sponsored_priority) live directly
 * on products and are synced by Sponsorship::activate() / ::deactivate().
 *
 * @property int         $id
 * @property int         $seller_id
 * @property int         $product_id
 * @property string      $plan_type         free|red|black
 * @property int         $boost_score       10|30|70
 * @property string      $status            active|expired|cancelled
 * @property Carbon|null $start_at
 * @property Carbon|null $end_at
 * @property float       $amount_charged
 * @property string|null $payment_reference
 * @property bool        $was_paid
 * @property bool        $used_free_quota
 * @property array|null  $ai_tags
 * @property string|null $ai_ad_copy
 * @property int         $impressions
 * @property int         $clicks
 * @property int         $conversions
 */
class Sponsorship extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_id',
        'product_id',
        'plan_type',
        'boost_score',
        'status',
        'start_at',
        'end_at',
        'amount_charged',
        'payment_reference',
        'was_paid',
        'used_free_quota',
        'ai_tags',
        'ai_ad_copy',
        'impressions',
        'clicks',
        'conversions',
    ];

    protected $casts = [
        'start_at'       => 'datetime',
        'end_at'         => 'datetime',
        'was_paid'       => 'boolean',
        'used_free_quota'=> 'boolean',
        'ai_tags'        => 'array',
        'amount_charged' => 'decimal:3',
        'impressions'    => 'integer',
        'clicks'         => 'integer',
        'conversions'    => 'integer',
    ];

    // ── Boost scores per plan ─────────────────────────────────────────────────

    public const BOOST = [
        'free'  => 10,
        'red'   => 30,
        'black' => 70,
    ];

    // ── Per-activation price (TND) ────────────────────────────────────────────
    // Black plan uses free quota first; fallback price if quota exhausted.

    public const PRICE = [
        'free'  => 5.000,    // green sellers pay per use
        'red'   => 2.000,    // discounted for red
        'black' => 0.000,    // free (from quota)
    ];

    public const BLACK_FREE_PER_WEEK = 3;

    // ── Relationships ─────────────────────────────────────────────────────────

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeForSeller($query, int $sellerId)
    {
        return $query->where('seller_id', $sellerId);
    }

    // ── Business logic helpers ────────────────────────────────────────────────

    /**
     * How many free Black-plan activations this seller has used this week.
     */
    public static function blackFreeUsedThisWeek(int $sellerId): int
    {
        return static::where('seller_id', $sellerId)
            ->where('plan_type', 'black')
            ->where('used_free_quota', true)
            ->where('created_at', '>=', Carbon::now()->startOfWeek())
            ->count();
    }

    /**
     * How many free slots remain for this seller this week.
     */
    public static function blackFreeRemaining(int $sellerId): int
    {
        $used = static::blackFreeUsedThisWeek($sellerId);
        return max(0, static::BLACK_FREE_PER_WEEK - $used);
    }

    /**
     * True if this product already has an active sponsorship.
     */
    public static function hasActiveForProduct(int $productId): bool
    {
        return static::where('product_id', $productId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Sync product's is_sponsored / sponsored_priority flags.
     * Called after activate/deactivate.
     */
    public static function syncProductFlags(int $productId): void
    {
        $active = static::where('product_id', $productId)
            ->where('status', 'active')
            ->orderByDesc('boost_score')
            ->first();

        if ($active) {
            Product::where('id', $productId)->update([
                'is_sponsored'       => true,
                'sponsored_priority' => $active->boost_score,
                'sponsored_at'       => $active->start_at,
            ]);
        } else {
            Product::where('id', $productId)->update([
                'is_sponsored'       => false,
                'sponsored_priority' => 0,
            ]);
        }
    }

    /**
     * Expire any sponsorships whose end_at has passed.
     * Called by a scheduled job or on-read check.
     */
    public static function expireOverdue(): int
    {
        $expired = static::where('status', 'active')
            ->whereNotNull('end_at')
            ->where('end_at', '<', Carbon::now())
            ->get();

        foreach ($expired as $s) {
            $s->update(['status' => 'expired']);
            static::syncProductFlags($s->product_id);
        }

        return $expired->count();
    }

    // ── Computed attributes ────────────────────────────────────────────────────

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function getCtrAttribute(): float
    {
        if ($this->impressions === 0) return 0.0;
        return round($this->clicks / $this->impressions * 100, 2);
    }

    public function getConversionRateAttribute(): float
    {
        if ($this->clicks === 0) return 0.0;
        return round($this->conversions / $this->clicks * 100, 2);
    }
}