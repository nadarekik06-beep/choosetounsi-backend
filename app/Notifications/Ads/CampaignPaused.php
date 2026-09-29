<?php

namespace App\Notifications\Ads;

use App\Models\Sponsorship;

/**
 * The platform paused a campaign (wallet empty, out of stock, product inactive,
 * plan change, admin). Not sent for manual pauses or the daily budget cap.
 */
class CampaignPaused extends CampaignNotification
{
    public function __construct(Sponsorship $campaign, private string $reason)
    {
        parent::__construct($campaign);
    }

    protected function type(): string
    {
        return 'ad_campaign_paused';
    }

    protected function icon(): string
    {
        return 'pause-circle';
    }

    protected function title(): string
    {
        return __('ads.notif.paused.title', ['name' => $this->productName()]);
    }

    protected function body(): string
    {
        return __("ads.notif.paused.{$this->reason}", ['name' => $this->productName()]);
    }
}
