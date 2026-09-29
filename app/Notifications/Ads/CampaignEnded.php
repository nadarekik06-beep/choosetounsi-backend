<?php

namespace App\Notifications\Ads;

use App\Models\Sponsorship;

/** A campaign finished (completed / cancelled / rejected) — with its results summary. */
class CampaignEnded extends CampaignNotification
{
    /** @param array{spend: float, clicks: int, orders: int, revenue: float, roas: ?float, refund?: float} $summary */
    public function __construct(Sponsorship $campaign, private string $outcome, private array $summary)
    {
        parent::__construct($campaign);
    }

    protected function type(): string
    {
        return "ad_campaign_{$this->outcome}";
    }

    protected function icon(): string
    {
        return $this->outcome === Sponsorship::STATUS_REJECTED ? 'x-circle' : 'flag';
    }

    protected function title(): string
    {
        return __("ads.notif.ended.{$this->outcome}.title", ['name' => $this->productName()]);
    }

    protected function body(): string
    {
        $body = __("ads.notif.ended.{$this->outcome}.body", [
            'spend'   => $this->money($this->summary['spend'] ?? 0),
            'clicks'  => (int) ($this->summary['clicks'] ?? 0),
            'orders'  => (int) ($this->summary['orders'] ?? 0),
            'revenue' => $this->money($this->summary['revenue'] ?? 0),
            'refund'  => $this->money($this->summary['refund'] ?? 0),
            'reason'  => (string) $this->campaign->rejection_reason,
        ]);
        if ($this->outcome !== Sponsorship::STATUS_REJECTED && ($this->summary['roas'] ?? null) !== null) {
            $body .= ' ' . __('ads.notif.roas', ['roas' => number_format($this->summary['roas'], 2)]);
        }
        return $body;
    }

    public function toDatabase(object $notifiable): array
    {
        return parent::toDatabase($notifiable) + ['summary' => $this->summary];
    }
}
