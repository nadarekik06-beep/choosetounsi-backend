<?php

namespace App\Http\Resources\Ads;

use App\Models\Sponsorship;
use App\Services\Ads\AdClock;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** A seller's campaign as the seller dashboard sees it. Money in TND (3 decimals). */
class AdCampaignResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var Sponsorship $c */
        $c       = $this->resource;
        $product = $c->relationLoaded('product') ? $c->product : null;
        $image   = $product && $product->relationLoaded('primaryImage') ? $product->primaryImage : null;
        $today   = $c->spent_today_date?->toDateString() === AdClock::today();
        $ctr     = $c->impressions > 0 ? round($c->clicks / $c->impressions, 4) : null;

        return [
            'id'               => $c->id,
            'status'           => $c->status,
            'paused_reason'    => $c->paused_reason,
            'rejection_reason' => $c->rejection_reason,
            'pricing_model'    => $c->pricing_model,
            'goal'             => $c->goal,
            'product'          => $product ? [
                'id'        => $product->id,
                'name'      => $product->name,
                'slug'      => $product->slug,
                'price'     => (float) $product->price,
                'image_url' => $image ? Storage::url($image->image_path) : null,
            ] : null,
            'daily_budget'     => $this->money($c->daily_budget),
            'total_budget'     => $this->money($c->total_budget),
            'max_cpc'          => $this->money($c->max_cpc),
            'spent_today'      => $today ? (float) $c->spent_today : 0.0,
            'spent_total'      => (float) $c->spent_total,
            'placements'       => $c->placements,            // null = all placements
            'targeting'        => [
                'gender'      => $c->target_gender,
                'wilayas'     => $c->target_wilaya_ids,
                'categories'  => $c->target_category_ids,
                'price_min'   => $this->money($c->target_price_min),
                'price_max'   => $this->money($c->target_price_max),
            ],
            'start_at'         => $c->start_at?->toIso8601String(),
            'end_at'           => $c->end_at?->toIso8601String(),
            'ended_at'         => $c->ended_at?->toIso8601String(),
            'readiness_score'  => $c->readiness_score,
            'tips'             => $c->optimizer['tips'] ?? [],
            'tips_checked_at'  => $c->optimizer['checked_at'] ?? null,
            'ad_copy'          => $c->ai_ad_copy,
            'tags'             => $c->ai_tags,
            'stats'            => [
                'impressions' => (int) $c->impressions,
                'clicks'      => (int) $c->clicks,
                'ctr'         => $ctr,
                'orders'      => (int) $c->attributed_orders,
                'revenue'     => (float) $c->attributed_revenue,
            ],
            'can'              => [
                'edit'   => $c->isOpen(),
                'pause'  => $c->status === Sponsorship::STATUS_ACTIVE,
                'resume' => $c->status === Sponsorship::STATUS_PAUSED
                            && !in_array($c->paused_reason, [Sponsorship::PAUSE_ADMIN, Sponsorship::PAUSE_PLAN_DOWNGRADE], true),
                'cancel' => $c->isOpen(),
            ],
            'created_at'       => $c->created_at?->toIso8601String(),
        ];
    }

    private function money($value): ?float
    {
        return $value === null ? null : round((float) $value, 3);
    }
}
