<?php

namespace App\Services\Ads;

use App\Exceptions\Ads\InsufficientAdFunds;
use App\Models\Sponsorship;
use App\Models\SponsorshipEvent;
use App\Notifications\Ads\AdWalletLow;
use App\Notifications\Ads\CampaignBudgetAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Impressions and clicks reported by the storefront (with the signed ad token).
 *
 * Impressions are free, deduplicated per viewer/campaign/placement (30 min) and
 * feed the frequency cap. Clicks are always logged; they are billable only once
 * per viewer and campaign per 24 h, never for bots, bursts from one IP or the
 * seller themselves. A billable click is charged from the wallet at the price
 * sealed in the token, capped by what's left of today's (and the total) budget.
 * When the budget or the wallet runs out the campaign pauses itself.
 */
class AdEventService
{
    const BOT_UA = '/bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|headless|lighthouse|pingdom|curl|wget|python-requests|httpclient|scrapy/i';

    public function __construct(
        private AdTokenService $tokens,
        private AdSettings $settings,
        private AdPricing $pricing,
        private AdWalletService $wallets,
        private SponsorshipService $campaigns,
    ) {}

    /**
     * @param array{user_id: ?int, session_id: ?string, ip: ?string, user_agent: ?string} $viewer
     * @return array{accepted: bool, reason?: string, billable?: bool, cost?: float}
     */
    public function record(string $token, string $event, array $viewer): array
    {
        $payload = $this->tokens->verify($token);
        if (!$payload) {
            return ['accepted' => false, 'reason' => 'invalid_token'];
        }
        $campaign = Sponsorship::find((int) $payload['c']);
        if (!$campaign) {
            return ['accepted' => false, 'reason' => 'unknown_campaign'];
        }

        // The viewer is who the ad was served to; a later login keeps the same browser session.
        $ctx = [
            'user_id'    => $viewer['user_id'] ?? ($payload['u'] ?? null),
            'session_id' => $viewer['session_id'] ?? ($payload['s'] ?? null),
            'ip_hash'    => !empty($viewer['ip']) ? hash('sha256', $viewer['ip'] . '|' . config('app.key')) : null,
            'bot'        => (bool) preg_match(self::BOT_UA, (string) ($viewer['user_agent'] ?? '')),
            'placement'  => (string) $payload['pl'],
            'request_id' => (string) $payload['r'],
            'cpc'        => (float) ($payload['cpc'] ?? 0),
        ];
        $ctx['actor'] = $ctx['user_id'] ? "u{$ctx['user_id']}" : ($ctx['session_id'] ? "s{$ctx['session_id']}" : ($ctx['ip_hash'] ? "ip{$ctx['ip_hash']}" : null));

        return $event === SponsorshipEvent::CLICK
            ? $this->click($campaign, $ctx)
            : $this->impression($campaign, $ctx);
    }

    private function impression(Sponsorship $c, array $ctx): array
    {
        if ($ctx['bot']) {
            return ['accepted' => false, 'reason' => 'bot'];
        }
        $window = max(1, $this->settings->int('impression_dedupe_minutes')) * 60;
        if ($ctx['actor'] && !Cache::add("ads:imp:{$ctx['actor']}:{$c->id}:{$ctx['placement']}", 1, $window)) {
            return ['accepted' => false, 'reason' => 'duplicate'];
        }

        $this->insertEvent($c, SponsorshipEvent::IMPRESSION, $ctx, ['billable' => false]);
        Sponsorship::whereKey($c->id)->increment('impressions');
        AdStats::add($c->id, $ctx['placement'], ['impressions' => 1]);

        if ($ctx['actor']) {
            $key = "ads:freq:{$ctx['actor']}:{$c->id}:" . AdClock::today();
            Cache::add($key, 0, 86400 * 2);
            Cache::increment($key);
            if ($ctx['placement'] === 'entry_popup') {
                Cache::put("ads:popup:{$ctx['actor']}", 1, 86400);
            }
        }
        return ['accepted' => true];
    }

    private function click(Sponsorship $c, array $ctx): array
    {
        $reason = $this->nonBillableReason($c, $ctx);
        $pauseReason = null;

        $result = DB::transaction(function () use ($c, $ctx, &$reason, &$pauseReason) {
            $c = Sponsorship::whereKey($c->id)->lockForUpdate()->first();

            // Under the campaign lock: one billable click per viewer per window.
            if ($reason === null && $ctx['actor'] && $this->recentBillableClick($c->id, $ctx)) {
                $reason = 'duplicate';
            }

            $cost = $credit = 0.0;
            if ($reason === null) {
                $today      = AdClock::today();
                $spentToday = $c->spent_today_date?->toDateString() === $today ? (float) $c->spent_today : 0.0;
                $left       = (float) $c->daily_budget - $spentToday;
                if ($c->total_budget !== null) {
                    $left = min($left, (float) $c->total_budget - (float) $c->spent_total);
                }
                $price = round(min($ctx['cpc'], (float) $c->max_cpc, max(0, $left)), 3);

                if ($price < 0.001) {
                    $reason = 'budget_exhausted';
                } else {
                    try {
                        $charge = $this->wallets->charge($c, $price);
                        $cost   = $charge['total'];
                        $credit = $charge['credit'];
                        $c->update([
                            'spent_today'      => round($spentToday + $cost, 3),
                            'spent_today_date' => $today,
                            'spent_total'      => round((float) $c->spent_total + $cost, 3),
                        ]);
                    } catch (InsufficientAdFunds $e) {
                        $reason = 'wallet_empty';
                        $pauseReason = Sponsorship::PAUSE_WALLET_EMPTY;
                    }
                }
            }

            $billable = $reason === null;
            // Counters show real clicks: no duplicates, bots or self-clicks (they're still logged).
            $countable = !in_array($reason, ['duplicate', 'bot', 'ip_burst', 'self_click'], true);
            $this->insertEvent($c, SponsorshipEvent::CLICK, $ctx, [
                'billable' => $billable, 'countable' => $countable, 'cost' => $cost, 'credit_cost' => $credit,
            ]);

            if ($countable) {
                Sponsorship::whereKey($c->id)->increment('clicks');
                AdStats::add($c->id, $ctx['placement'], ['clicks' => 1, 'cost' => $cost]);
            }
            return ['campaign' => $c->fresh(), 'billable' => $billable, 'cost' => $cost];
        });

        if ($result['billable']) {
            $this->afterCharge($result['campaign']);
        } elseif ($pauseReason) {
            $this->autoPause($result['campaign'], $pauseReason);
        }

        return ['accepted' => true, 'billable' => $result['billable'], 'cost' => $result['cost']]
            + ($reason ? ['reason' => $reason] : []);
    }

    /** Why this click can't be charged (null = it can, pending the duplicate check under lock). */
    private function nonBillableReason(Sponsorship $c, array $ctx): ?string
    {
        if ($c->pricing_model !== Sponsorship::PRICING_CPC || $ctx['cpc'] <= 0) {
            return 'not_cpc';
        }
        if ($c->status !== Sponsorship::STATUS_ACTIVE) {
            return 'not_active';
        }
        if ($ctx['bot']) {
            return 'bot';
        }
        if ($ctx['user_id'] && (int) $ctx['user_id'] === (int) $c->seller_id) {
            return 'self_click';
        }
        if ($ctx['ip_hash']) {
            $key = "ads:ipclicks:{$ctx['ip_hash']}:" . intdiv(time(), 60);
            Cache::add($key, 0, 120);
            if (Cache::increment($key) > max(1, $this->settings->int('bot_max_clicks_per_minute'))) {
                return 'ip_burst';
            }
        }
        return null;
    }

    private function recentBillableClick(int $campaignId, array $ctx): bool
    {
        $since = now()->subHours(max(1, $this->settings->int('click_dedupe_hours')));
        return SponsorshipEvent::where('sponsorship_id', $campaignId)->where('event', SponsorshipEvent::CLICK)
            ->where('billable', true)->where('created_at', '>=', $since)
            ->where(function ($q) use ($ctx) {
                if ($ctx['user_id']) {
                    $q->orWhere('user_id', $ctx['user_id']);
                }
                if ($ctx['session_id']) {
                    $q->orWhere('session_id', $ctx['session_id']);
                }
                if (!$ctx['user_id'] && !$ctx['session_id'] && $ctx['ip_hash']) {
                    $q->orWhere('ip_hash', $ctx['ip_hash']);
                }
            })->exists();
    }

    /** Budget / wallet follow-ups after a paid click: pause when exhausted, alert once a day. */
    private function afterCharge(Sponsorship $c): void
    {
        $floor = $this->pricing->floorCpc($c->product?->category_id);
        $left  = (float) $c->daily_budget - (float) $c->spent_today;

        if ($c->total_budget !== null && (float) $c->total_budget - (float) $c->spent_total < $floor) {
            $this->safely(fn () => $this->campaigns->complete($c));
            return;
        }
        if ($this->wallets->available($c->seller_id) < $floor) {
            $this->autoPause($c, Sponsorship::PAUSE_WALLET_EMPTY);
            return;
        }
        if ($left < $floor) {
            $this->autoPause($c, Sponsorship::PAUSE_BUDGET_TODAY);
        } elseif ((float) $c->spent_today >= $this->settings->float('budget_alert_ratio') * (float) $c->daily_budget
            && Cache::add("ads:alert:budget:{$c->id}:" . AdClock::today(), 1, 86400)) {
            $this->safely(fn () => $c->seller?->notify(new CampaignBudgetAlert($c)));
        }

        // Wallet low: less than N days of the seller's active daily budgets.
        $dailyTotal = (float) Sponsorship::where('seller_id', $c->seller_id)->where('status', Sponsorship::STATUS_ACTIVE)
            ->where('pricing_model', Sponsorship::PRICING_CPC)->sum('daily_budget');
        $available = $this->wallets->available($c->seller_id);
        if ($dailyTotal > 0 && $available < $this->settings->float('wallet_low_days') * $dailyTotal
            && Cache::add("ads:alert:wallet:{$c->seller_id}:" . AdClock::today(), 1, 86400)) {
            $this->safely(fn () => $c->seller?->notify(new AdWalletLow($c, $available, $dailyTotal)));
        }
    }

    private function autoPause(Sponsorship $c, string $reason): void
    {
        if ($c->status === Sponsorship::STATUS_ACTIVE) {
            $this->safely(fn () => $this->campaigns->pause($c, $reason));
        }
    }

    private function insertEvent(Sponsorship $c, string $event, array $ctx, array $extra): void
    {
        SponsorshipEvent::create(array_merge([
            'sponsorship_id' => $c->id,
            'event'          => $event,
            'placement'      => mb_substr($ctx['placement'], 0, 30),
            'user_id'        => $ctx['user_id'],
            'session_id'     => $ctx['session_id'],
            'request_id'     => $ctx['request_id'],
            'ip_hash'        => $ctx['ip_hash'],
            'created_at'     => now(),
        ], $extra));
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('[AdEventService] follow-up failed: ' . $e->getMessage());
        }
    }
}
