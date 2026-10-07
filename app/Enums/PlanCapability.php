<?php

namespace App\Enums;

/**
 * The single registry of plan capabilities: features the backend actually
 * enforces (seller.feature middleware, PlanGate, services). Stored per plan in
 * subscription_plans.features as { key: bool }.
 *
 * Adding a capability is a code change: the flag means nothing until some code
 * checks it. Admins toggle them per plan and may override the public wording
 * (subscription_plans.capability_display), never add new ones.
 */
enum PlanCapability: string
{
    case Analytics    = 'analytics';
    case AiTools      = 'ai_tools';
    case BlackHub     = 'black_hub';
    case Promotions   = 'promotions';
    case Coupons      = 'coupons';
    case Sponsorships = 'sponsorships';

    /** Order on the public pricing card. */
    public const PUBLIC_ORDER = ['promotions', 'coupons', 'sponsorships', 'analytics', 'ai_tools', 'black_hub'];

    /** Admin-facing name, with how it is enforced. */
    public function adminLabel(): string
    {
        return match ($this) {
            self::Analytics    => 'Advanced analytics & sales forecast',
            self::AiTools      => 'AI seller tools (price optimizer, descriptions, predictor)',
            self::BlackHub     => 'Black Pepper hub (AI hub, VIP lounge, insights, free sponsor quota)',
            self::Promotions   => 'Promotions & flash sales',
            self::Coupons      => 'Store coupons',
            self::Sponsorships => 'Sponsored products (paid boosts)',
        };
    }

    /** Default public label (French), shown on /become-a-vendor. */
    public function label(): string
    {
        return match ($this) {
            self::Analytics    => 'Statistiques avancées',
            self::AiTools      => 'Outils IA',
            self::BlackHub     => 'Black Hub : IA avancée et salon VIP',
            self::Promotions   => 'Promotions et ventes flash',
            self::Coupons      => 'Codes promo',
            self::Sponsorships => 'Produits sponsorisés',
        };
    }

    /** Default public description (French). */
    public function description(): string
    {
        return match ($this) {
            self::Analytics    => 'Tableaux de bord détaillés et prévision des ventes.',
            self::AiTools      => 'Optimiseur de prix, recommandations et descriptions générées par IA.',
            self::BlackHub     => 'Radar de croissance, centre de profit et avantages VIP.',
            self::Promotions   => 'Créez des promotions et des ventes flash sur vos produits.',
            self::Coupons      => 'Créez des codes promo pour votre boutique.',
            self::Sponsorships => 'Boostez vos produits avec des campagnes sponsorisées.',
        };
    }

    /** Icon key from PlanDisplayFeature::ICONS. */
    public function icon(): string
    {
        return match ($this) {
            self::Analytics    => 'chart',
            self::AiTools      => 'sparkles',
            self::BlackHub     => 'crown',
            self::Promotions   => 'zap',
            self::Coupons      => 'ticket',
            self::Sponsorships => 'megaphone',
        };
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(fn(self $c) => $c->value, self::cases());
    }

    /** key => admin label (what SubscriptionPlan::FEATURES used to hold). */
    public static function adminLabels(): array
    {
        $out = [];
        foreach (self::cases() as $c) $out[$c->value] = $c->adminLabel();
        return $out;
    }

    /** Admin catalogue: everything the plan editor needs to render a capability. */
    public static function catalogue(): array
    {
        return array_map(fn(self $c) => [
            'key'                => $c->value,
            'label'              => $c->adminLabel(),
            'public_label'       => $c->label(),
            'public_description' => $c->description(),
            'icon'               => $c->icon(),
        ], self::cases());
    }
}
