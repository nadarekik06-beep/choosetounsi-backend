<?php
// app/Models/Sponsorship.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Sponsorship model — one row per sponsorship activation.
 *
 * CHANGES vs previous version:
 *   - Added targeting fields: target_gender, target_wilaya_ids,
 *     target_category_ids, target_price_min, target_price_max
 *   - Added matchesUser(User|null) helper for feed filtering
 *   - Updated fillable + casts accordingly
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
        // ── Targeting (new) ───────────────────────────────────────────────
        'target_gender',
        'target_wilaya_ids',
        'target_category_ids',
        'target_price_min',
        'target_price_max',
    ];

    protected $casts = [
        'start_at'             => 'datetime',
        'end_at'               => 'datetime',
        'was_paid'             => 'boolean',
        'used_free_quota'      => 'boolean',
        'ai_tags'              => 'array',
        'amount_charged'       => 'decimal:3',
        'impressions'          => 'integer',
        'clicks'               => 'integer',
        'conversions'          => 'integer',
        // ── Targeting casts ───────────────────────────────────────────────
        'target_wilaya_ids'    => 'array',
        'target_category_ids'  => 'array',
        'target_price_min'     => 'decimal:3',
        'target_price_max'     => 'decimal:3',
    ];

    // ── Plan constants ────────────────────────────────────────────────────────

    public const BOOST = [
        'free'  => 10,
        'red'   => 30,
        'black' => 70,
    ];

    public const PRICE = [
        'free'  => 5.000,
        'red'   => 2.000,
        'black' => 0.000,
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

    // ── Targeting helper ──────────────────────────────────────────────────────

    /**
     * Returns true if this sponsorship should be shown to the given user.
     *
     * Rules:
     *   - A null targeting field means "no restriction — show to everyone".
     *   - For authenticated users, each non-null field must match the user's profile/preferences.
     *   - For guests (user === null), ALL targeting restrictions are ignored
     *     (guest cannot be matched, so we show to guests regardless of targeting).
     *
     * @param  User|null          $user
     * @param  UserPreference|null $prefs  Pre-loaded to avoid N+1
     * @return bool
     */
    public function matchesUser(?User $user, ?UserPreference $prefs = null): bool
    {
        // Guests see all sponsored products (targeting is a relevance boost, not a hard gate)
        if ($user === null) {
            return true;
        }

        // ── Gender targeting ─────────────────────────────────────────────
        if ($this->target_gender !== null && $prefs?->gender !== null) {
            $targetGender = $this->target_gender;
            $userGender   = $prefs->gender;

            // "unisex" targeting matches everyone
            // User with "unisex" preference matches any target gender
            $genderMatch = $targetGender === 'unisex'
                || $userGender === 'unisex'
                || $targetGender === $userGender;

            if (!$genderMatch) {
                return false;
            }
        }

        // ── Category targeting ───────────────────────────────────────────
        if (!empty($this->target_category_ids) && !empty($prefs?->category_ids)) {
            $targetCats = array_map('intval', (array) $this->target_category_ids);
            $userCats   = array_map('intval', (array) $prefs->category_ids);

            // Must share at least one category
            if (empty(array_intersect($targetCats, $userCats))) {
                return false;
            }
        }

        // ── Wilaya targeting ─────────────────────────────────────────────
        if (!empty($this->target_wilaya_ids)) {
            // wilaya is stored directly on the User model
            $userWilaya = $user->wilaya ?? null;

            if ($userWilaya !== null) {
                $targetWilayas = array_map('strtolower', (array) $this->target_wilaya_ids);
                if (!in_array(strtolower($userWilaya), $targetWilayas, true)) {
                    return false;
                }
            }
            // If user has no wilaya set, do not filter them out
        }

        // ── Price range targeting ────────────────────────────────────────
        // Logic: sponsored product's target price range must overlap with user's preferred range.
        if ($this->target_price_min !== null && $prefs?->price_max !== null) {
            if ((float) $prefs->price_max < (float) $this->target_price_min) {
                return false; // user's max budget is below the target minimum
            }
        }

        if ($this->target_price_max !== null && $prefs?->price_min !== null) {
            if ((float) $prefs->price_min > (float) $this->target_price_max) {
                return false; // user's minimum budget exceeds the target maximum
            }
        }

        return true;
    }

    // ── Business logic helpers ────────────────────────────────────────────────
    public static function maybeResetBlackQuota(int $sellerId): void
{
    // intentionally empty — blackFreeUsedThisWeek() already scopes
    // to Carbon::now()->startOfWeek(), so it self-resets each week.
}

    public static function blackFreeUsedThisWeek(int $sellerId): int
    {
        return static::where('seller_id', $sellerId)
            ->where('plan_type', 'black')
            ->where('used_free_quota', true)
            ->where('created_at', '>=', Carbon::now()->startOfWeek())
            ->count();
    }

    public static function blackFreeRemaining(int $sellerId): int
    {
        $used = static::blackFreeUsedThisWeek($sellerId);
        return max(0, static::BLACK_FREE_PER_WEEK - $used);
    }

    public static function hasActiveForProduct(int $productId): bool
    {
        return static::where('product_id', $productId)
            ->where('status', 'active')
            ->exists();
    }

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

    // ── Computed attributes ───────────────────────────────────────────────────

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