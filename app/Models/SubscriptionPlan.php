<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed seller plan. `slug` is what seller_subscriptions.current_plan
 * and seller_applications.plan store.
 *
 * @property string     $slug
 * @property int        $tier            0 green | 1 red | 2 black (dashboard experience + sponsorship pricing)
 * @property float|null $commission_rate flat % — overrides the platform tier table when set
 * @property float      $commission_reduction points removed from the platform tier rate
 * @property array      $features
 */
class SubscriptionPlan extends Model
{
    /**
     * Features that are actually implemented and enforced in the backend.
     * key => [label, how it's enforced]
     */
    public const FEATURES = [
        'analytics'    => 'Advanced analytics & sales forecast',
        'ai_tools'     => 'AI seller tools (price optimizer, descriptions, predictor)',
        'black_hub'    => 'Black Pepper hub (AI hub, VIP lounge, insights, free sponsor quota)',
        'promotions'   => 'Promotions & flash sales',
        'coupons'      => 'Store coupons',
        'sponsorships' => 'Sponsored products (paid boosts)',
    ];

    /** Legacy slug for each tier — used where pricing/UI is still tier-based. */
    public const TIER_KEYS = [0 => 'free', 1 => 'red', 2 => 'black'];

    protected $fillable = [
        'slug', 'name', 'description', 'badge_color', 'display_order', 'tier',
        'price_monthly', 'price_yearly', 'trial_days',
        'commission_rate', 'commission_reduction',
        'max_products', 'max_images_per_product', 'max_sponsored_products',
        'features', 'is_active', 'is_default', 'archived_at',
    ];

    protected $casts = [
        'features'               => 'array',
        'is_active'              => 'boolean',
        'is_default'             => 'boolean',
        'archived_at'            => 'datetime',
        'tier'                   => 'integer',
        'display_order'          => 'integer',
        'trial_days'             => 'integer',
        'price_monthly'          => 'float',
        'price_yearly'           => 'float',
        'commission_rate'        => 'float',
        'commission_reduction'   => 'float',
        'max_products'           => 'integer',
        'max_images_per_product' => 'integer',
        'max_sponsored_products' => 'integer',
    ];

    /** Per-request cache: slug => plan. Cleared whenever a plan is saved. */
    private static array $cache = [];

    protected static function booted(): void
    {
        static::saved(fn() => self::$cache = []);
        static::deleted(fn() => self::$cache = []);
    }

    // ── Lookup ─────────────────────────────────────────────────────────────

    /** Resolve a slug; unknown / archived slugs fall back to the default plan. */
    public static function forSlug(?string $slug): self
    {
        if (empty(self::$cache)) {
            self::$cache = self::all()->keyBy('slug')->all();
        }
        return self::$cache[$slug ?? ''] ?? self::defaultPlan();
    }

    public static function defaultPlan(): self
    {
        if (empty(self::$cache)) {
            self::$cache = self::all()->keyBy('slug')->all();
        }
        foreach (self::$cache as $plan) {
            if ($plan->is_default) return $plan;
        }
        // Should never happen (migration seeds a default) — keep the platform usable
        return self::$cache['free'] ?? new self([
            'slug' => 'free', 'name' => 'Free', 'tier' => 0, 'price_monthly' => 0,
            'commission_reduction' => 0, 'max_products' => 30, 'features' => [],
        ]);
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeNotArchived($q) { return $q->whereNull('archived_at'); }
    public function scopeOffered($q)     { return $q->whereNull('archived_at')->where('is_active', true); }
    public function scopeOrdered($q)     { return $q->orderBy('display_order')->orderBy('price_monthly'); }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function hasFeature(string $key): bool
    {
        return (bool) (($this->features ?? [])[$key] ?? false);
    }

    /** 'free' | 'red' | 'black' — for code paths still keyed by the legacy tiers. */
    public function tierKey(): string
    {
        return self::TIER_KEYS[$this->tier] ?? 'free';
    }

    /** Ordering used for upgrade / downgrade direction. */
    public function rank(): array
    {
        return [$this->tier, (float) $this->price_monthly];
    }

    public function isHigherThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    public function priceFor(string $period): float
    {
        return $period === 'yearly' && $this->price_yearly !== null
            ? (float) $this->price_yearly
            : (float) $this->price_monthly;
    }

    public function isFree(): bool
    {
        return (float) $this->price_monthly <= 0;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
