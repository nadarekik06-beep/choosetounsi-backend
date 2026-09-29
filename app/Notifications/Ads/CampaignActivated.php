<?php

namespace App\Notifications\Ads;

/** A campaign went live. */
class CampaignActivated extends CampaignNotification
{
    protected function type(): string
    {
        return 'ad_campaign_activated';
    }

    protected function title(): string
    {
        return __('ads.notif.activated.title', ['name' => $this->productName()]);
    }

    protected function body(): string
    {
        return __('ads.notif.activated.body', [
            'name'   => $this->productName(),
            'budget' => $this->money($this->campaign->daily_budget ?? 0),
            'cpc'    => $this->money($this->campaign->max_cpc ?? 0),
        ]);
    }
}
