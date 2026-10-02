<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Weekly forecast summary (opt-in), built from the shop snapshot. */
class ForecastDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(public array $shop, public string $shopName)
    {
        $this->onQueue(config('seller_notifications.queue'));
    }

    public function via($notifiable): array
    {
        return filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $n = $this->shop['next28'] ?? null;
        return [
            'type'   => 'forecast',
            'event'  => 'digest',
            'action' => 'forecast_digest',
            'icon'   => 'gauge',
            'title'  => __('forecast.digest.title'),
            'body'   => $n ? __('forecast.digest.body', ['low' => $n['low'], 'high' => $n['high'], 'risk' => count($this->shop['stock']['at_risk'] ?? [])])
                           : __('forecast.digest.next28_none'),
            'link'   => '/seller/ai-tools?tab=sales',
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $locale = app()->getLocale();
        $n      = $this->shop['next28'] ?? null;
        $money  = fn($v) => number_format((float) $v, 0, ',', ' ');
        $acc    = $this->shop['accuracy'] ?? null;

        $data = [
            'subject'  => __('forecast.digest.subject', ['shop' => $this->shopName]),
            'headline' => __('forecast.digest.headline'),
            'next28'   => $n ? __('forecast.digest.next28', ['low' => $n['low'], 'high' => $n['high'],
                              'rev_low' => $money($n['revenue_low']), 'rev_high' => $money($n['revenue_high'])])
                            : __('forecast.digest.next28_none'),
            'atRisk'   => array_map(fn($r) => __('forecast.digest.at_risk_row', ['name' => $r['name'], 'days' => $r['days_left']]),
                              array_slice($this->shop['stock']['at_risk'] ?? [], 0, 8)),
            'events'   => array_values(array_map(fn($e) => __('forecast.digest.event_row', ['event' => $e['names'][$locale] ?? $e['names']['fr'], 'days' => $e['days_until']]),
                              array_filter($this->shop['events'] ?? [], fn($e) => $e['days_until'] >= 0 && $e['days_until'] <= 42))),
            'accuracy' => $acc ? __('forecast.digest.accuracy', ['low' => $acc['low'], 'high' => $acc['high'], 'actual' => $acc['actual']]) : null,
            'url'      => rtrim(config('app.frontend_url'), '/') . '/seller/ai-tools?tab=sales',
            'cta'      => __('forecast.notif.cta'),
            'footer'   => __('forecast.digest.footer'),
            'rtl'      => $locale === 'ar',
            'logoUrl'  => config('seller_notifications.logo_url'),
        ];
        return (new MailMessage)->subject($data['subject'])
            ->view(['emails.forecast.digest', 'emails.forecast.digest-text'], $data);
    }
}
