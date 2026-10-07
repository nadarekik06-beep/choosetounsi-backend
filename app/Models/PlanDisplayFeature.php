<?php

namespace App\Models;

use App\Services\PricingCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * A marketing bullet on a plan's public pricing card. Display only: nothing
 * in the backend checks these, unlike the capabilities in subscription_plans.features.
 *
 * @property int         $plan_id
 * @property string      $label
 * @property string|null $description
 * @property string|null $icon
 * @property bool        $included   false = shown muted with ✗, for comparison
 * @property bool        $highlight
 * @property int         $sort_order
 */
class PlanDisplayFeature extends Model
{
    public const MAX_PER_PLAN = 15;

    /** Fixed icon set; the storefront and the admin panel map each key to an icon. */
    public const ICONS = [
        'check', 'star', 'zap', 'sparkles', 'crown', 'chart', 'megaphone', 'ticket',
        'tag', 'percent', 'package', 'image', 'truck', 'headset', 'shield', 'gift',
        'clock', 'users', 'rocket', 'heart',
    ];

    protected $fillable = ['plan_id', 'label', 'description', 'icon', 'included', 'highlight', 'sort_order'];

    protected $casts = [
        'included'   => 'boolean',
        'highlight'  => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(fn() => PricingCatalog::flush());
        static::deleted(fn() => PricingCatalog::flush());
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /** Snapshot used in audit logs and admin responses. */
    public function toSnapshot(): array
    {
        return [
            'id'          => $this->id,
            'label'       => $this->label,
            'description' => $this->description,
            'icon'        => $this->icon,
            'included'    => (bool) $this->included,
            'highlight'   => (bool) $this->highlight,
            'sort_order'  => (int) $this->sort_order,
        ];
    }
}
