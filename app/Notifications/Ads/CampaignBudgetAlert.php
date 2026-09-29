<?php

namespace App\Notifications\Ads;

/** 80 % (ads.budget_alert_ratio) of today's budget is spent — at most once a day per campaign. */
class CampaignBudgetAlert extends CampaignNotification
{
    protected function type(): string
    {
        return 'ad_campaign_budget_alert';
    }

    protected function icon(): string
    {
        return 'gauge';
    }

    protected function title(): string
    {
        return __('ads.notif.budget_alert.title', ['name' => $this->productName()]);
    }

    protected function body(): string
    {
        return __('ads.notif.budget_alert.body', [
            'spent'  => $this->money($this->campaign->spent_today),
            'budget' => $this->money($this->campaign->daily_budget),
        ]);
    }
}
