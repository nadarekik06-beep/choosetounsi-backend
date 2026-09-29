<?php

namespace App\Notifications\Ads;

use App\Models\Sponsorship;

/** The ad wallet covers less than ads.wallet_low_days of the seller's active daily budgets. */
class AdWalletLow extends CampaignNotification
{
    public function __construct(Sponsorship $campaign, private float $available, private float $dailyTotal)
    {
        parent::__construct($campaign);
    }

    protected function type(): string
    {
        return 'ad_wallet_low';
    }

    protected function icon(): string
    {
        return 'wallet';
    }

    protected function link(): string
    {
        return '/seller/promote/wallet';
    }

    protected function title(): string
    {
        return __('ads.notif.wallet_low.title');
    }

    protected function body(): string
    {
        return __('ads.notif.wallet_low.body', [
            'available' => $this->money($this->available),
            'days'      => $this->dailyTotal > 0 ? number_format($this->available / $this->dailyTotal, 1) : '0',
        ]);
    }
}
