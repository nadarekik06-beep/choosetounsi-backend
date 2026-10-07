<?php

namespace App\Notifications;

use App\Services\Profit\MoneyFormat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Centre de profit goal alerts (bell + opt-in e-mail). Params are raw numbers;
 * texts are rendered here in the seller's language (lang/xx/profit.php).
 *
 *   milestone     {month, pct, sales, goal, remaining, days_left}
 *   behind_pace   {month, goal, projection, required_daily, current_daily, days_left}
 *   weekly        {month, week_sales, week_orders, pct?, projection?, goal?}
 *   recap         {month, sales, goal?, pct?, net, hit, streak}
 *   new_goal      {month, suggested?}
 */
class ProfitGoalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public const ICONS = [
        'milestone' => 'target', 'behind_pace' => 'trending-down', 'weekly' => 'gauge',
        'recap' => 'flag', 'new_goal' => 'target',
    ];

    public function __construct(public string $event, public array $params, public array $channels)
    {
        $this->onQueue(config('seller_notifications.queue'));
    }

    public function via($notifiable): array
    {
        $via = [];
        if (in_array('bell', $this->channels, true)) $via[] = 'database';
        if (in_array('email', $this->channels, true) && filled($notifiable->email)) $via[] = 'mail';
        return $via;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'   => 'profit_goal',
            'event'  => $this->event,
            'action' => 'goal_' . $this->event,
            'icon'   => self::ICONS[$this->event] ?? 'target',
            'title'  => $this->title(),
            'body'   => $this->body(),
            'link'   => '/seller/black/profit',
            'month'  => $this->params['month'] ?? null,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $data = [
            'subject' => $this->title(),
            'title'   => $this->title(),
            'body'    => $this->body(),
            'cta'     => __('profit.notify.cta'),
            'url'     => rtrim((string) config('app.frontend_url'), '/') . '/seller/black/profit',
            'footer'  => __('profit.notify.footer'),
            'rtl'     => app()->getLocale() === 'ar',
            'logoUrl' => config('seller_notifications.logo_url'),
        ];
        return (new MailMessage)->subject($data['subject'])
            ->view(['emails.forecast.alert', 'emails.forecast.alert-text'], $data);
    }

    private function vars(): array
    {
        $p = $this->params;
        $money = fn($k) => isset($p[$k]) && $p[$k] !== null ? MoneyFormat::dt((float) $p[$k]) : '';
        return [
            'month'      => isset($p['month']) ? MoneyFormat::month($p['month']) : '',
            'pct'        => isset($p['pct']) ? (string) round((float) $p['pct']) : '',
            'sales'      => $money('sales'),
            'goal'       => $money('goal'),
            'remaining'  => $money('remaining'),
            'projection' => $money('projection'),
            'required'   => $money('required_daily'),
            'current'    => $money('current_daily'),
            'week_sales' => $money('week_sales'),
            'net'        => $money('net'),
            'suggested'  => $money('suggested'),
            'days'       => (string) ($p['days_left'] ?? ''),
            'orders'     => (string) ($p['week_orders'] ?? ''),
            'streak'     => (string) ($p['streak'] ?? 0),
        ];
    }

    public function title(): string
    {
        $v = $this->vars();
        return match ($this->event) {
            'milestone'   => (int) $this->params['pct'] >= 100 ? __('profit.notify.milestone_done_title', $v) : __('profit.notify.milestone_title', $v),
            'behind_pace' => __('profit.notify.pace_title', $v),
            'weekly'      => __('profit.notify.weekly_title', $v),
            'recap'       => __('profit.notify.recap_title', $v),
            'new_goal'    => __('profit.notify.new_goal_title', $v),
        };
    }

    public function body(): string
    {
        $v = $this->vars();
        $p = $this->params;
        return match ($this->event) {
            'milestone'   => (int) $p['pct'] >= 100 ? __('profit.notify.milestone_done_body', $v) : __('profit.notify.milestone_body', $v),
            'behind_pace' => __('profit.notify.pace_body', $v),
            'weekly'      => __('profit.notify.weekly_body', $v)
                . (!empty($p['goal']) ? ' ' . __('profit.notify.weekly_goal', $v) : ' ' . __('profit.notify.weekly_no_goal')),
            'recap'       => empty($p['goal']) ? __('profit.notify.recap_no_goal', $v)
                : (!empty($p['hit']) ? trans_choice('profit.notify.recap_hit', (int) $p['streak'], $v) : __('profit.notify.recap_missed', $v)),
            'new_goal'    => empty($p['suggested']) ? __('profit.notify.new_goal_body', $v) : __('profit.notify.new_goal_suggested', $v),
        };
    }
}
