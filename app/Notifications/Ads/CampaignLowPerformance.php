<?php

namespace App\Notifications\Ads;

use App\Models\Sponsorship;

/** The optimizer found a campaign under-performing (low CTR / ROAS < 1) — at most weekly per kind. */
class CampaignLowPerformance extends CampaignNotification
{
    /** @param string[] $codes low_ctr | low_roas */
    public function __construct(Sponsorship $campaign, private array $codes)
    {
        parent::__construct($campaign);
    }

    protected function type(): string
    {
        return 'ad_campaign_low_performance';
    }

    protected function icon(): string
    {
        return 'alert-triangle';
    }

    protected function title(): string
    {
        return __('ads.notif.low_performance.title', ['name' => $this->productName()]);
    }

    protected function body(): string
    {
        return implode(' ', array_map(fn ($c) => __("ads.notif.low_performance.{$c}"), $this->codes));
    }
}
