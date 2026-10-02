<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Forecast alert (bell + optional e-mail), in the seller's language:
 *   stockout   — a product/variant runs out within the seller's alert window
 *   event      — a calendar event relevant to their category is ~5 weeks away
 *   sales_drop — last 14 days sold below the range we forecast
 *
 * $params are the action params built by App\Services\Forecast\ActionBuilder.
 * Sent by App\Services\Forecast\ForecastAlerts (deduped there).
 */
class ForecastAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(public string $type, public array $params, public bool $withEmail)
    {
        $this->onQueue(config('seller_notifications.queue'));
    }

    public function via($notifiable): array
    {
        return $this->withEmail && filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'       => 'forecast',
            'event'      => $this->type,
            'action'     => 'forecast_' . $this->type,
            'icon'       => ['stockout' => 'package-x', 'event' => 'calendar', 'sales_drop' => 'trending-down'][$this->type] ?? 'alert-triangle',
            'title'      => $this->line('title'),
            'body'       => $this->line('body'),
            'link'       => $this->path(),
            'product_id' => $this->params['product_id'] ?? null,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $data = [
            'subject'  => $this->line('subject'),
            'title'    => $this->line('title'),
            'body'     => $this->line('body'),
            'cta'      => __('forecast.notif.cta'),
            'url'      => rtrim(config('app.frontend_url'), '/') . $this->path(),
            'footer'   => __('forecast.notif.footer'),
            'rtl'      => app()->getLocale() === 'ar',
            'logoUrl'  => config('seller_notifications.logo_url'),
        ];
        return (new MailMessage)->subject($data['subject'])
            ->view(['emails.forecast.alert', 'emails.forecast.alert-text'], $data);
    }

    private function line(string $part): string
    {
        $p      = $this->params;
        $locale = app()->getLocale();
        $name   = $p['product_name'] ?? '';
        if (!empty($p['variant'])) $name .= ' (' . ($p['variant'][$locale] ?? $p['variant']['fr'] ?? '') . ')';
        $date   = fn(?string $d) => $d ? CarbonImmutable::parse($d)->locale($locale)->translatedFormat('j F') : '';

        $key = "forecast.notif.{$this->type}.$part";
        if ($this->type === 'event' && $part === 'body' && isset($p['change_pct']) && !empty($p['effect_reliable'])) {
            $key = 'forecast.notif.event.body_effect';
        }
        return __($key, [
            'name'   => $name,
            'days'   => $p['days_left'] ?? $p['days_until'] ?? '',
            'date'   => $date($p['stockout_date'] ?? null),
            'qty'    => $p['reorder_qty'] ?? '',
            'by'     => $date($p['reorder_by'] ?? null),
            'event'  => $p['event'][$locale] ?? $p['event']['fr'] ?? '',
            'pct'    => isset($p['change_pct']) ? (($p['change_pct'] > 0 ? '+' : '') . (int) round($p['change_pct'])) : '',
            'actual' => $p['actual'] ?? '',
            'low'    => $p['expected_low'] ?? '',
        ]);
    }

    private function path(): string
    {
        $pid = $this->params['product_id'] ?? null;
        return '/seller/ai-tools?tab=sales' . ($pid ? "&product_id={$pid}" : '');
    }
}
