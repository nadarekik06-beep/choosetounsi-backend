<?php
// app/Models/Sponsorship.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Support\Wilayas;
use Carbon\Carbon;

/**
 * A sponsoring campaign for one product (the table predates campaigns, hence the name).
 *
 * Lifecycle (App\Services\Ads\SponsorshipService is the only writer):
 *   draft → active ⇄ paused → completed
 *               ↘ cancelled (seller/admin)      draft|active|paused → rejected (admin)
 *   expired = legacy end state of pricing_model=legacy_daily rows.
 *
 * pricing_model=cpc campaigns are charged per click from the seller's ad wallet,
 * capped by daily_budget; legacy_daily rows were prepaid per day and run until end_at.
 * At most one open (draft|active|paused) campaign per product — enforced by the
 * service and by the generated open_product_id UNIQUE column.
 */
class Sponsorship extends Model
{
    use HasFactory;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_EXPIRED   = 'expired';

    /** Campaigns that still occupy their product (one per product). */
    public const OPEN_STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED];

    public const PAUSE_MANUAL           = 'manual';
    public const PAUSE_BUDGET_TODAY     = 'budget_exhausted_today';
    public const PAUSE_WALLET_EMPTY     = 'wallet_empty';
    public const PAUSE_OUT_OF_STOCK     = 'out_of_stock';
    public const PAUSE_PLAN_DOWNGRADE   = 'plan_downgrade';
    public const PAUSE_PRODUCT_INACTIVE = 'product_inactive';
    public const PAUSE_ADMIN            = 'admin';

    public const PRICING_CPC    = 'cpc';
    public const PRICING_LEGACY = 'legacy_daily';

    public const PLACEMENTS = [
        'home_row', 'home_inline', 'search_top', 'category_top',
        'product_similar', 'cart_cross_sell', 'entry_popup', 'email_digest',
    ];

    protected $fillable = [
        'seller_id',
        'product_id',
        'plan_type',
        'pricing_model',
        'goal',
        'boost_score',
        'status',
        'paused_reason',
        'paused_at',
        'rejection_reason',
        'start_at',
        'end_at',
        'ended_at',
        'daily_budget',
        'total_budget',
        'max_cpc',
        'spent_total',
        'spent_today',
        'spent_today_date',
        'placements',
        'optimizer',
        'readiness_score',
        'amount_charged',
        'payment_reference',
        'was_paid',
        'used_free_quota',
        'ai_tags',
        'ai_ad_copy',
        'impressions',
        'clicks',
        'conversions',
        'attributed_orders',
        'attributed_revenue',
        'target_gender',
        'target_wilaya_ids',
        'target_category_ids',
        'target_price_min',
        'target_price_max',
    ];

    protected $casts = [
        'start_at'             => 'datetime',
        'end_at'               => 'datetime',
        'ended_at'             => 'datetime',
        'paused_at'            => 'datetime',
        'spent_today_date'     => 'date:Y-m-d',
        'was_paid'             => 'boolean',
        'used_free_quota'      => 'boolean',
        'ai_tags'              => 'array',
        'placements'           => 'array',
        'optimizer'            => 'array',
        'amount_charged'       => 'decimal:3',
        'daily_budget'         => 'decimal:3',
        'total_budget'         => 'decimal:3',
        'max_cpc'              => 'decimal:3',
        'spent_total'          => 'decimal:3',
        'spent_today'          => 'decimal:3',
        'attributed_revenue'   => 'decimal:3',
        'readiness_score'      => 'integer',
        'impressions'          => 'integer',
        'clicks'               => 'integer',
        'conversions'          => 'integer',
        'attributed_orders'    => 'integer',
        'target_wilaya_ids'    => 'array',
        'target_category_ids'  => 'array',
        'target_price_min'     => 'decimal:3',
        'target_price_max'     => 'decimal:3',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function events()
    {
        return $this->hasMany(SponsorshipEvent::class);
    }

    public function dailyStats()
    {
        return $this->hasMany(SponsorshipDailyStat::class);
    }

    public function walletTransactions()
    {
        return $this->hasMany(AdWalletTransaction::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** draft | active | paused — the campaigns that occupy their product. */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /**
     * Active and not past its end date. Read paths use this instead of expiring rows
     * themselves; ads:complete-ended does the status write on a schedule.
     */
    public function scopeLive($query)
    {
        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>', Carbon::now()));
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
            // Default address, else latest order (memoized on the User instance)
            $userWilaya = $user->targetingWilaya();

            if ($userWilaya !== null
                && !in_array($userWilaya, Wilayas::normalizeMany((array) $this->target_wilaya_ids), true)) {
                return false;
            }
            // If we don't know where the user is, do not filter them out
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

    public static function hasOpenForProduct(int $productId): bool
    {
        return static::where('product_id', $productId)->open()->exists();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isCpc(): bool
    {
        return $this->pricing_model === self::PRICING_CPC;
    }

    /**
     * products.is_sponsored / sponsored_priority are a display cache of "has an
     * active campaign". The ad server doesn't rank with them.
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