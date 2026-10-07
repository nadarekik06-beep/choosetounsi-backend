<?php

namespace App\Services;

use App\Enums\PlanCapability;
use App\Models\PlanDisplayFeature;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Cache;

/**
 * Public pricing cards for /become-a-vendor (GET /api/seller-plans).
 *
 * Only plans offered to sellers, only public fields. The payload is cached
 * forever and flushed whenever a plan, a display feature or the default
 * commission table changes, so admin edits show up on the next request.
 */
class PricingCatalog
{
    public const CACHE_KEY = 'pricing:seller-plans:v1';

    public function __construct(private CommissionService $commission) {}

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<array<string, mixed>> */
    public function plans(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn() => SubscriptionPlan::offered()
            ->ordered()
            ->with('displayFeatures')
            ->get()
            ->map(fn(SubscriptionPlan $p) => $this->card($p))
            ->values()
            ->all());
    }

    /** One plan as the storefront renders it. */
    public function card(SubscriptionPlan $plan): array
    {
        $range = $this->commission->rateRangeForPlan($plan->slug);

        return [
            'key'            => $plan->slug,
            'name'           => $plan->name,
            'tagline'        => $plan->tagline,
            'badge_color'    => $plan->badge_color,
            'tier'           => $plan->tier,
            'price'          => (float) $plan->price_monthly,
            'price_yearly'   => $plan->price_yearly,
            'currency'       => 'TND',
            'billing_period' => 'monthly',
            'trial_days'     => $plan->trial_days,
            'max_products'   => $plan->max_products,
            'features'       => self::capabilityFlags($plan),
            'commission_min' => $range['min'],
            'commission_max' => $range['max'],
            'is_default'     => (bool) $plan->is_default,
            'is_recommended' => (bool) $plan->is_recommended,
            'limits'           => self::limits($plan),
            'capabilities'     => self::capabilities($plan),
            'display_features' => $plan->displayFeatures->map(fn(PlanDisplayFeature $f) => [
                'label'       => $f->label,
                'description' => $f->description,
                'icon'        => $f->icon,
                'included'    => (bool) $f->included,
                'highlight'   => (bool) $f->highlight,
            ])->values()->all(),
        ];
    }

    /** { key: bool } for every registered capability. */
    public static function capabilityFlags(SubscriptionPlan $plan): array
    {
        $out = [];
        foreach (PlanCapability::keys() as $key) $out[$key] = $plan->hasFeature($key);
        return $out;
    }

    /**
     * Visible capabilities in public order, with the plan's wording overrides.
     *
     * @return list<array{key: string, label: string, description: ?string, icon: string, included: bool}>
     */
    public static function capabilities(SubscriptionPlan $plan): array
    {
        $display = $plan->capability_display ?? [];
        $out = [];
        foreach (PlanCapability::PUBLIC_ORDER as $key) {
            $cap = PlanCapability::from($key);
            $o   = $display[$key] ?? [];
            if (array_key_exists('visible', $o) && !$o['visible']) continue;
            $out[] = [
                'key'         => $key,
                'label'       => trim((string) ($o['label'] ?? '')) ?: $cap->label(),
                'description' => trim((string) ($o['description'] ?? '')) ?: $cap->description(),
                'icon'        => $cap->icon(),
                'included'    => $plan->hasFeature($key),
            ];
        }
        return $out;
    }

    /**
     * Limits as French card lines; null means unlimited.
     *
     * @return list<array{key: string, value: ?int, label: string}>
     */
    public static function limits(SubscriptionPlan $plan): array
    {
        $hidden = $plan->hidden_limits ?? [];
        $out = [];
        foreach (SubscriptionPlan::PUBLIC_LIMITS as $key) {
            if (in_array($key, $hidden, true)) continue;
            // The sponsored cap means nothing without the sponsoring capability.
            if ($key === 'max_sponsored_products' && !$plan->hasFeature('sponsorships')) continue;
            $value = $plan->{$key};
            if ($value === 0) continue;
            $out[] = ['key' => $key, 'value' => $value, 'label' => self::limitLabel($key, $value)];
        }
        return $out;
    }

    public static function limitLabel(string $key, ?int $value): string
    {
        $n = $value === null ? null : number_format($value, 0, ',', ' ');
        return match ($key) {
            'max_products' => $value === null
                ? 'Produits illimités'
                : ($value === 1 ? '1 produit' : "{$n} produits"),
            'max_images_per_product' => $value === null
                ? 'Photos illimitées par produit'
                : ($value === 1 ? '1 photo par produit' : "{$n} photos par produit"),
            'max_sponsored_products' => $value === null
                ? 'Produits sponsorisés illimités'
                : ($value === 1 ? '1 produit sponsorisé à la fois' : "{$n} produits sponsorisés à la fois"),
            default => (string) $value,
        };
    }
}
