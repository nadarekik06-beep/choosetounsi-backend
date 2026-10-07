<?php

namespace App\Services\Profit;

use App\Models\ProfitAlertSetting;
use App\Models\RevenueGoal;
use App\Models\User;
use App\Notifications\ProfitGoalNotification;
use App\Services\PlanGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Goal alerts of the Centre de profit. Every check is idempotent:
 *   milestones   each threshold once per goal (revenue_goals.milestones_sent, row lock)
 *   pace         at most once every profit.pace_alert.cooldown_days per goal
 *   weekly       once per day stamp (profit_alert_settings.last_weekly_on)
 *   recap        once per month (last_recap_month) — also snapshots the goal
 *   new goal     once per month (last_reminder_month)
 * Only sellers whose plan still has black_hub are notified.
 */
class GoalAlerts
{
    public function __construct(
        private SellerRevenueService $revenue,
        private ProfitCenter $center,
        private PlanGate $gate,
    ) {}

    public function eligible(int $sellerId): bool
    {
        return $this->gate->feature($sellerId, 'black_hub') === null;
    }

    /** Thresholds already reached by the given sales (used to silence them when a goal is edited). */
    public function reached(float $sales, float $goal): array
    {
        if ($goal <= 0) return [];
        $pct = $sales / $goal * 100;
        return array_values(array_filter(config('profit.milestones'), fn($t) => $pct >= $t));
    }

    /** @return int|null the milestone just sent */
    public function checkMilestones(int $sellerId, ?CarbonImmutable $now = null): ?int
    {
        $now   = $now ?? $this->revenue->now();
        $month = $now->startOfMonth();
        $goal  = RevenueGoal::where('seller_id', $sellerId)->where('month', $month->format('Y-m'))->first();
        if (!$goal || $goal->goal_amount <= 0) return null;

        $settings = ProfitAlertSetting::forSeller($sellerId);
        if (!$settings->wants('milestones') || !$this->eligible($sellerId)) return null;

        $sales = $this->revenue->salesBetween($sellerId, $month, $month->addMonthNoOverflow())['sales'];

        $sent = DB::transaction(function () use ($goal, $sales) {
            $row  = RevenueGoal::whereKey($goal->id)->lockForUpdate()->first();
            $done = $row->milestones_sent ?? [];
            $new  = array_values(array_diff($this->reached($sales, $row->goal_amount), $done));
            if (!$new) return null;
            $row->milestones_sent = array_values(array_unique(array_merge($done, $new)));
            $row->save();
            return max($new);                       // crossing 25 and 50 at once → one alert, 50 %
        });
        if ($sent === null) return null;

        $daysLeft = $month->daysInMonth - (int) $now->format('j') + 1;
        $this->send($sellerId, $settings, 'milestone', [
            'month'     => $goal->month,
            'pct'       => $sent,
            'sales'     => $sales,
            'goal'      => $goal->goal_amount,
            'remaining' => max(0, $goal->goal_amount - $sales),
            'days_left' => $daysLeft,
        ]);
        return $sent;
    }

    public function checkPace(int $sellerId, ?CarbonImmutable $now = null, bool $force = false): bool
    {
        $now  = $now ?? $this->revenue->now();
        $goal = RevenueGoal::where('seller_id', $sellerId)->where('month', $now->format('Y-m'))->first();
        if (!$goal || $goal->goal_amount <= 0) return false;

        $cfg      = config('profit.pace_alert');
        $settings = ProfitAlertSetting::forSeller($sellerId);
        if (!$settings->wants('pace') || !$this->eligible($sellerId)) return false;
        if (!$force) {
            if ((int) $now->format('j') < $cfg['min_day']) return false;
            if ($goal->last_pace_alert_at && $goal->last_pace_alert_at->gt($now->subDays($cfg['cooldown_days']))) return false;
        }

        $data = $this->center->build($sellerId, $now);
        $proj = $data['projection'];
        $prog = $data['progress'];
        if ($prog['status'] !== 'behind' || !$proj) return false;
        if ($proj['amount'] > $goal->goal_amount * (1 - $cfg['min_gap_pct'] / 100)) return false;

        $goal->forceFill(['last_pace_alert_at' => now()])->save();
        $this->send($sellerId, $settings, 'behind_pace', [
            'month'          => $goal->month,
            'goal'           => $goal->goal_amount,
            'projection'     => $proj['amount'],
            'required_daily' => $prog['required_daily'],
            'current_daily'  => $prog['current_daily'],
            'days_left'      => $data['days_left'],
        ]);
        return true;
    }

    public function weekly(int $sellerId, ?CarbonImmutable $now = null, bool $force = false): bool
    {
        $now      = $now ?? $this->revenue->now();
        $settings = ProfitAlertSetting::forSeller($sellerId);
        if (!$settings->wants('weekly') || !$this->eligible($sellerId)) return false;
        if (!$force && $settings->last_weekly_on && $settings->last_weekly_on->toDateString() === $now->toDateString()) return false;

        $today = $now->startOfDay();
        $week  = $this->revenue->salesBetween($sellerId, $today->subDays(7), $today);
        $data  = $this->center->build($sellerId, $now);

        $this->stamp($settings, ['last_weekly_on' => $now->toDateString()]);
        $this->send($sellerId, $settings, 'weekly', [
            'month'       => $data['month'],
            'week_sales'  => $week['sales'],
            'week_orders' => $week['orders'],
            'goal'        => $data['goal']['amount'] ?? null,
            'pct'         => $data['progress']['pct'] ?? null,
            'projection'  => $data['projection']['amount'] ?? null,
        ]);
        return true;
    }

    /** On the 1st: snapshot last month's goal and send the recap. */
    public function monthlyRecap(int $sellerId, ?CarbonImmutable $now = null, bool $force = false): bool
    {
        $now    = $now ?? $this->revenue->now();
        $prevM  = $now->startOfMonth()->subMonthNoOverflow();
        $prevYm = $prevM->format('Y-m');
        $totals = $this->revenue->monthly($sellerId, $prevM, $now->startOfMonth())[$prevYm];
        $goal   = RevenueGoal::where('seller_id', $sellerId)->where('month', $prevYm)->first();

        if ($goal) {
            $goal->forceFill([
                'achieved_revenue' => $totals['sales'], 'achieved_orders' => $totals['orders'],
                'achieved_net' => $totals['net'], 'closed_at' => now(),
            ])->save();
        }

        $settings = ProfitAlertSetting::forSeller($sellerId);
        if (!$settings->wants('monthly_recap') || !$this->eligible($sellerId)) return false;
        if (!$force && $settings->last_recap_month === $prevYm) return false;
        if (!$goal && $totals['sales'] <= 0) return false;

        $goals  = RevenueGoal::where('seller_id', $sellerId)->orderBy('month')->get()->keyBy('month');
        $months = $this->revenue->monthly($sellerId, $this->revenue->monthStart($goals->keys()->first() ?? $prevYm)->min($prevM), $now->startOfMonth());
        $streak = $this->center->streaks($goals, $months, $prevYm, $totals['sales']);
        $hit    = $goal && $totals['sales'] >= $goal->goal_amount;

        $this->stamp($settings, ['last_recap_month' => $prevYm]);
        $this->send($sellerId, $settings, 'recap', [
            'month'  => $prevYm,
            'sales'  => $totals['sales'],
            'net'    => $totals['net'],
            'goal'   => $goal?->goal_amount,
            'pct'    => $goal && $goal->goal_amount > 0 ? $totals['sales'] / $goal->goal_amount * 100 : null,
            'hit'    => $hit,
            'streak' => $streak['current'],
        ]);
        return true;
    }

    /** On the 1st: no goal yet for the new month, but the seller used goals recently. */
    public function newGoalReminder(int $sellerId, ?CarbonImmutable $now = null, bool $force = false): bool
    {
        $now   = $now ?? $this->revenue->now();
        $ym    = $now->format('Y-m');
        if (RevenueGoal::where('seller_id', $sellerId)->where('month', $ym)->exists()) return false;

        $settings = ProfitAlertSetting::forSeller($sellerId);
        if (!$settings->wants('new_goal_reminder') || !$this->eligible($sellerId)) return false;
        if (!$force && $settings->last_reminder_month === $ym) return false;

        $data = $this->center->build($sellerId, $now);
        $this->stamp($settings, ['last_reminder_month' => $ym]);
        $this->send($sellerId, $settings, 'new_goal', [
            'month'     => $ym,
            'suggested' => $data['suggestion']['presets']['realistic'] ?? null,
        ]);
        return true;
    }

    // ── Plumbing ────────────────────────────────────────────────────────────

    private function stamp(ProfitAlertSetting $settings, array $values): void
    {
        $settings->fill($values)->save();   // creates the row with the defaults on first use
    }

    private function send(int $sellerId, ProfitAlertSetting $settings, string $event, array $params): void
    {
        $channels = array_values(array_filter([
            $settings->channel_bell ? 'bell' : null,
            $settings->channel_email ? 'email' : null,
        ]));
        $seller = User::find($sellerId);
        if (!$seller || !$channels) return;
        try {
            $seller->notify(new ProfitGoalNotification($event, $params, $channels));
        } catch (\Throwable $e) {
            Log::warning("[ProfitGoal] {$event} notification failed for seller {$sellerId}: " . $e->getMessage());
        }
    }
}
